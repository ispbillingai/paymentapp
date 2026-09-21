package com.ispledger.paymentapp;

/** Validate the complete destination before accepting a device key. */
final class ConnectionPolicy {
    static boolean validUrl(String value, boolean debug) {
        try {
            java.net.URI uri=new java.net.URI(value);
            return ("https".equalsIgnoreCase(uri.getScheme()) || debug && "http".equalsIgnoreCase(uri.getScheme()))
                && uri.getHost()!=null && !uri.getHost().isEmpty() && uri.getUserInfo()==null && uri.getFragment()==null
                && (uri.getPort()==-1 || uri.getPort()>0 && uri.getPort()<=65535);
        } catch(Exception invalid){return false;}
    }
}
