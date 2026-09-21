package com.ispledger.paymentapp;

/** Run with javac/java; no Android device or third-party test libraries required. */
public final class ConnectionPolicyCheck {
    public static void main(String[] args) {
        expect("https://ispbillingpay.com/v1/device/messages",false,true);
        expect("HTTPS://gateway.example/device",false,true);
        expect("http://localhost:8787/device",true,true);
        expect("http://gateway.example/device",false,false);
        expect("https://",false,false);
        expect("https:///v1/device/messages",false,false);
        expect("https://user:secret@gateway.example/device",false,false);
        expect("https://gateway.example/device#fragment",false,false);
        expect("https://gateway.example:99999/device",false,false);
        expect("https://gateway.example:0/device",false,false);
        expect("https://gateway.example:443/device",false,true);
        expect("https://gateway.example/has space",false,false);
        expect("file:///device",true,false);
        System.out.println("13 destination validation checks passed.");
    }
    private static void expect(String input,boolean debug,boolean expected) {
        if(ConnectionPolicy.validUrl(input,debug)!=expected)throw new AssertionError(input);
    }
}
