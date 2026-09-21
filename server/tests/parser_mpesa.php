<?php
/**
 * Kenyan M-Pesa receipts. No database, no network.
 *
 * Two things broke these before they were tested: the receipts say "Ksh" while the
 * merchant's currency is "KES", and a till receipt states the amount BEFORE the
 * word "received". Both read as amount 0, which records the payment and never
 * matches it. Money the owner SENT must never be read as money coming in.
 */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/src/Parser.php';

$failures = 0;
$check = static function ($what, $got, $want) use (&$failures) {
    if ($got !== $want) {
        $failures++;
        echo "FAIL $what\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n";
    }
};

$cases = [
    ['personal receipt', 'SIK4ABC9XY Confirmed.You have received Ksh500.00 from JOHN DOE 0712345678 on 21/9/26 at 10:15 AM  New M-PESA balance is Ksh1,250.00. Transaction cost, Ksh0.00.',
        ['kind' => 'credit', 'trx_id' => 'SIK4ABC9XY', 'amount' => 500.0, 'currency' => 'KES', 'payer_msisdn' => '0712345678', 'payer_name' => 'JOHN DOE']],
    // Safaricom hides part of the payer's number. The payment is still real money;
    // it simply cannot be matched by number, and waits to be claimed by name.
    ['masked number', 'TJ21QW8ERT Confirmed. You have received Ksh1,000.00 from MARY WANJIKU 0722***456 on 21/9/26 at 11:02 AM New M-PESA balance is Ksh2,250.00.',
        ['kind' => 'credit', 'trx_id' => 'TJ21QW8ERT', 'amount' => 1000.0, 'currency' => 'KES', 'payer_msisdn' => '', 'payer_name' => 'MARY WANJIKU']],
    ['till receipt, amount first', 'TJ21ZX7CVB Confirmed. Ksh250.00 received from 254712345678 PETER KAMAU on 21/9/26 at 12:30 PM. New Account balance is Ksh9,500.00.',
        ['kind' => 'credit', 'trx_id' => 'TJ21ZX7CVB', 'amount' => 250.0, 'currency' => 'KES', 'payer_msisdn' => '254712345678', 'payer_name' => 'PETER KAMAU']],
    ['money sent is not money in', 'TJ21AA1BBB Confirmed. Ksh300.00 sent to JANE DOE 0733000111 on 21/9/26 at 1:00 PM. New M-PESA balance is Ksh950.00.',
        ['kind' => 'other', 'amount' => 0.0]],
    ['a balance line alone is not a payment', 'Your M-PESA balance was Ksh4,500.00 on 21/9/26 at 2:00 PM.',
        ['kind' => 'other', 'amount' => 0.0]],
    // Both of these reached the owner's listener on 21 Sep 2026 and were correctly
    // refused. They are kept here so a later change to the amount patterns cannot
    // quietly start counting money going out as money coming in.
    ['real receipt: money sent out', 'UIL0K7XB8J Confirmed. Ksh1.00 sent to FAITH  NDAMU 0768424304 on 21/9/26 at 12:53 PM. New M-PESA balance is Ksh0.00. Transaction cost, Ksh0.00.  Amount you can transact within the day is 488,997.00. See all your balances now https://saf.cx/iqIzU',
        ['kind' => 'other', 'amount' => 0.0]],
    ['real receipt: a Fuliza overdraft notice', 'UIL0K7XB8J Confirmed. Fuliza M-PESA amount is Ksh 1.00. Access Fee charged Ksh 0.01. Total Fuliza M-PESA outstanding amount is Ksh1919.00 due on 21/10/26. To check daily charges, Dial *334#OK Select Query Charges',
        ['kind' => 'other', 'amount' => 0.0]],
];
foreach ($cases as [$label, $text, $want]) {
    $got = Parser::parseMessage('mpesa_ke', $text, 'KES');
    foreach ($want as $field => $value) {
        $check("$label: $field", $got[$field], $value);
    }
}

$check('MPESA is an allowed sender for 254', Parser::providerForSender('MPESA', '254'), 'mpesa_ke');
$check('M-PESA with a hyphen too', Parser::providerForSender('M-PESA', '254'), 'mpesa_ke');
// A number is allowed only because the merchant named it. Unnamed, it is still
// nothing: the list is what decides, not the shape of the sender.
$check('an unlisted number is not a sender', Parser::providerForSender('0712345678', '254'), '');
$check('a number the merchant listed is', Parser::providerForSender('0712345678', '254', ['other' => ['0712345678']]), 'other');
$check('the label an owner sees', Parser::providerLabel('mpesa_ke'), 'M-Pesa');

echo $failures === 0 ? "PASS: M-Pesa receipts read correctly, and money sent is never counted.\n" : "$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
