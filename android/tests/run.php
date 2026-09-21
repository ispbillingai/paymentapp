<?php
/**
 * Runs SenderTest against the real Senders.java.
 *
 *   php android/tests/run.php
 *
 * Senders decides, on the phone, whether a message is a payment message at all,
 * and which networks the owner has ticked. Getting it wrong either drops real
 * payments or forwards the owner's private messages, so it is worth testing.
 *
 * The class mentions android.content.Context in one method, which cannot run off
 * a phone. Rather than keep a second copy that could drift, the one method is
 * stripped from the real source here, and this stops with an explanation if the
 * source no longer looks the way this expects.
 *
 * Needs javac and java on PATH, or JAVA_HOME set. Android Studio's bundled JDK
 * works: set JAVA_HOME to its jbr directory.
 */

$root = dirname(__DIR__);
$source = $root . '/app/src/main/java/com/ispledger/paymentapp/Senders.java';
$work = sys_get_temp_dir() . '/paymentapp-sender-test-' . getmypid();

$stop = static function ($why) {
    fwrite(STDERR, "STOPPED: $why\n");
    exit(1);
};

if (!is_file($source)) {
    $stop('Senders.java not found at ' . $source);
}
@mkdir($work, 0777, true);

// ---------------------------------------------------- a copy with no Android in it
$s = str_replace("import android.content.Context;\n", '', file_get_contents($source));
$marker = '    static boolean allowed(Context c, String sender) {';
$start = strpos($s, $marker);
if ($start === false) {
    $stop("allowed(Context, String) was not found in Senders.java.\n"
        . 'If it was renamed, update the marker in ' . __FILE__ . '.');
}
$end = strpos($s, "\n    }\n", $start);
if ($end === false) {
    $stop('Could not find the end of allowed(); its shape has changed.');
}
$s = substr($s, 0, $start) . substr($s, $end + strlen("\n    }\n"));
if (strpos($s, 'Context') !== false) {
    $stop("Senders.java still refers to Context after removing allowed().\n"
        . 'Anything needing a phone cannot be tested here; move it out of Senders or extend this script.');
}
$s = str_replace(["package com.ispledger.paymentapp;\n", 'final class Senders'], ['', 'public final class Senders'], $s);
$s = str_replace("\n    static ", "\n    public static ", $s);
file_put_contents($work . '/Senders.java', $s);
copy(__DIR__ . '/SenderTest.java', $work . '/SenderTest.java');
copy(__DIR__ . '/SenderListCheck.java', $work . '/SenderListCheck.java');

// ---------------------------------------------------------------------- build, run
$home = getenv('JAVA_HOME');
$bin = $home ? rtrim(str_replace('\\', '/', $home), '/') . '/bin/' : '';
$javac = $bin . 'javac';
$java = $bin . 'java';

$run = static function ($command) {
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("
", $output)];
};

[$code, $out] = $run(escapeshellarg($javac) . ' -nowarn -d ' . escapeshellarg($work . '/out')
    . ' ' . escapeshellarg($work . '/Senders.java')
    . ' ' . escapeshellarg($work . '/SenderTest.java')
    . ' ' . escapeshellarg($work . '/SenderListCheck.java'));
if ($code !== 0) {
    fwrite(STDERR, $out . "
");
    $stop('The tests did not compile. Is javac on PATH, or JAVA_HOME set to a JDK?');
}

$failed = 0;
foreach (['SenderTest', 'SenderListCheck'] as $test) {
    [$ran, $said] = $run(escapeshellarg($java) . ' -cp ' . escapeshellarg($work . '/out') . ' ' . $test);
    echo $said . "
";
    if ($ran !== 0) {
        $failed = 1;
    }
}

// leave nothing behind
foreach (glob($work . '/out/*') as $file) @unlink($file);
foreach (glob($work . '/*') as $file) is_dir($file) ? @rmdir($file) : @unlink($file);
@rmdir($work);

exit($failed);
