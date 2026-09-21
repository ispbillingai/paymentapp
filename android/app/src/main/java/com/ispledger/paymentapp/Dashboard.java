package com.ispledger.paymentapp;

import android.content.Context;
import android.content.Intent;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.net.Uri;
import android.os.Build;
import android.os.SystemClock;
import android.view.View;
import android.widget.*;
import android.text.Editable;
import android.text.TextWatcher;
import java.util.*;
import java.text.SimpleDateFormat;

/** Navigation and local operational views. Never exports keys or message contents. */
final class Dashboard {
    private final MainActivity a;
    private final String[] names = {"Overview", "Device connection", "Delivery queue", "Activity log", "Device health", "App updates", "Help & support"};
    private final int[] pages = {R.id.page_overview,R.id.page_connection,R.id.page_queue,R.id.page_activity,R.id.page_diagnostics,R.id.page_updates,R.id.page_help};
    private final int[] links = {R.id.nav_overview,R.id.nav_connection,R.id.nav_queue,R.id.nav_activity,R.id.nav_diagnostics,R.id.nav_updates,R.id.nav_help};
    private int page, days = 7;
    private boolean testing, retrying;
    private String filter = "", queueSignature = "";

    Dashboard(MainActivity activity) {
        a=activity;
        a.findViewById(R.id.menu_open).setOnClickListener(v -> drawer(true));
        a.findViewById(R.id.menu_close).setOnClickListener(v -> drawer(false));
        a.findViewById(R.id.nav_scrim).setOnClickListener(v -> drawer(false));
        for(int i=0;i<links.length;i++) { final int index=i; a.findViewById(links[i]).setOnClickListener(v -> navigate(index)); }
        a.findViewById(R.id.open_connection).setOnClickListener(v -> navigate(1));
        a.findViewById(R.id.open_diagnostics).setOnClickListener(v -> navigate(4));
        a.findViewById(R.id.test_connection).setOnClickListener(v -> test());
        a.findViewById(R.id.retry_queue).setOnClickListener(v -> retry());
        a.findViewById(R.id.pause_forwarding).setOnClickListener(v -> {
            if (!Prefs.configured(a)) { navigate(1); connectionFeedback("Pair this phone before enabling forwarding."); return; }
            boolean paused=!Prefs.paused(a); Prefs.paused(a,paused);
            Outbox.get(a).note(paused ? "Forwarding paused by owner" : "Forwarding resumed by owner");
            label(R.id.quick_feedback,paused ? "Paused. Incoming messages stay queued. A request already in progress may finish." : "Resumed. Pending messages will retry automatically.");
            if (!paused) new Thread(() -> Uploader.flush(a.getApplicationContext())).start();
            a.refreshDashboard();
        });
        a.findViewById(R.id.chart_period).setOnClickListener(v -> {days=days==7?14:7;refresh();});
        ((EditText)a.findViewById(R.id.activity_search)).addTextChangedListener(new TextWatcher(){
            public void beforeTextChanged(CharSequence s,int start,int count,int after){}
            public void onTextChanged(CharSequence s,int start,int before,int count){filter=s.toString().toLowerCase(Locale.ROOT);a.refreshDashboard();}
            public void afterTextChanged(Editable e){}
        });
        a.findViewById(R.id.share_diagnostics).setOnClickListener(v -> {
            Intent send=new Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT,"ISP Billing Pay diagnostics\n"+diagnostics());
            try { a.startActivity(Intent.createChooser(send,"Share diagnostic summary")); } catch(Exception e) { toast("No sharing app is available."); }
        });
        a.findViewById(R.id.android_settings).setOnClickListener(v -> open(new Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:"+a.getPackageName()))));
        a.findViewById(R.id.download_fallback).setOnClickListener(v -> open(new Intent(Intent.ACTION_VIEW,Uri.parse("https://ispbillingpay.com/download"))));
        a.findViewById(R.id.contact_support).setOnClickListener(v -> open(new Intent(Intent.ACTION_SENDTO,Uri.parse("mailto:contact@ispbillingpay.com"))));
    }
    private void open(Intent intent){try{a.startActivity(intent);}catch(Exception e){toast("No app is available to open this link.");}}
    private void toast(String text){Toast.makeText(a,text,Toast.LENGTH_LONG).show();}
    private void label(int id,String value){((TextView)a.findViewById(id)).setText(value);}
    private void drawer(boolean open){
        a.findViewById(R.id.nav_drawer).setVisibility(open?View.VISIBLE:View.GONE);
        a.findViewById(R.id.nav_scrim).setVisibility(open?View.VISIBLE:View.GONE);
        a.findViewById(R.id.app_shell).setImportantForAccessibility(open?View.IMPORTANT_FOR_ACCESSIBILITY_NO_HIDE_DESCENDANTS:View.IMPORTANT_FOR_ACCESSIBILITY_AUTO);
    }
    private void navigate(int index){
        page=index; drawer(false);
        for(int i=0;i<pages.length;i++) {a.findViewById(pages[i]).setVisibility(i==index?View.VISIBLE:View.GONE);a.findViewById(links[i]).setSelected(i==index);}
        label(R.id.page_title,names[index]);
        ((ScrollView)a.findViewById(R.id.page_scroll)).smoothScrollTo(0,0);
        if(index==1){a.findViewById(R.id.settings_fields).setVisibility(View.VISIBLE);label(R.id.settings_toggle,a.getString(R.string.settings_close));}
        refresh();
    }
    boolean back(){if(a.findViewById(R.id.nav_drawer).getVisibility()==View.VISIBLE){drawer(false);return true;}if(page!=0){navigate(0);return true;}return false;}
    void connectionFeedback(String message){label(R.id.connection_result,message);}
    private void test(){
        if(testing)return;
        if(!Prefs.configured(a)){navigate(1);connectionFeedback("Paste the device key from your dashboard and tap Save key & test connection.");return;}
        testing=true;a.findViewById(R.id.test_connection).setEnabled(false);
        label(R.id.test_connection,"Testing…");label(R.id.quick_feedback,"Contacting your gateway…");
        final long start=SystemClock.elapsedRealtime();final Context app=a.getApplicationContext();
        new Thread(() -> {String result=Uploader.ping(app);a.runOnUiThread(() -> {
            if(a.isFinishing()||a.isDestroyed())return;
            testing=false;a.findViewById(R.id.test_connection).setEnabled(true);label(R.id.test_connection,"Test connection");
            label(R.id.quick_feedback,result.isEmpty()?"Connection verified · "+(SystemClock.elapsedRealtime()-start)+" ms. Device key accepted.":result);
            a.refreshDashboard();
        });}).start();
    }
    private void retry(){
        if(retrying)return;
        if(!Prefs.configured(a)){navigate(1);return;}
        if(Prefs.paused(a)){toast("Resume forwarding from Overview before retrying.");return;}
        if(Outbox.get(a).waiting()==0){toast("Your queue is empty.");return;}
        retrying=true;a.findViewById(R.id.retry_queue).setEnabled(false);label(R.id.retry_queue,"Sending pending messages…");
        Context app=a.getApplicationContext();
        new Thread(() -> {try{Uploader.flush(app);}finally{a.runOnUiThread(() -> {
            if(a.isFinishing()||a.isDestroyed())return;
            retrying=false;a.findViewById(R.id.retry_queue).setEnabled(true);label(R.id.retry_queue,"Retry pending messages");
            toast(Outbox.get(app).waiting()==0?"All pending messages delivered.":"Retry finished. "+Outbox.get(app).waiting()+" messages remain queued.");refresh();
        });}}).start();
    }
    List<String[]> filteredActivity(){
        List<String[]> result=new ArrayList<>();
        for(String[] row:Outbox.get(a).recent())if(row[1].toLowerCase(Locale.ROOT).contains(filter))result.add(row);
        return result;
    }
    private String network(){
        ConnectivityManager cm=(ConnectivityManager)a.getSystemService(Context.CONNECTIVITY_SERVICE);
        NetworkInfo n=cm==null?null:cm.getActiveNetworkInfo();
        return n!=null&&n.isConnected()?n.getTypeName()+" connected":"Offline";
    }
    private String diagnostics(){
        return "App version: "+BuildConfig.VERSION_NAME+"\nAndroid: "+Build.VERSION.RELEASE+
            "\nNetwork: "+network()+"\nDevice key: "+(Prefs.configured(a)?"Configured":"Not configured")+
            "\nForwarding: "+(Prefs.paused(a)?"Paused":"Enabled")+"\nPending messages: "+Outbox.get(a).waiting()+
            "\nLast accepted contact: "+(Prefs.lastOkAt(a)==0?"Never":new SimpleDateFormat("d MMM yyyy HH:mm",Locale.getDefault()).format(new Date(Prefs.lastOkAt(a))))+
            "\nStatus: "+(Prefs.lastProblem(a).isEmpty()?"No recorded connection error":Prefs.lastProblem(a))+
            "\n\nDevice keys, phone numbers and payment message contents are excluded.";
    }
    void refresh(){
        Outbox box=Outbox.get(a);int[] today=box.stats(Outbox.day(System.currentTimeMillis()));
        label(R.id.delivered_today,String.valueOf(today[0]));label(R.id.retries_today,String.valueOf(today[1]));
        label(R.id.pause_forwarding,Prefs.paused(a)?"Resume forwarding":"Pause forwarding");
        label(R.id.open_connection,Prefs.configured(a)?"Manage device connection":"Set up this phone");
        label(R.id.chart_period,"Last "+days+" days · change period");
        int[] values=new int[days];String[] labels=new String[days];Calendar cal=Calendar.getInstance();cal.add(Calendar.DAY_OF_YEAR,1-days);int total=0;
        for(int i=0;i<days;i++){values[i]=box.stats(Outbox.day(cal.getTimeInMillis()))[0];total+=values[i];labels[i]=new SimpleDateFormat("d MMM",Locale.getDefault()).format(cal.getTime());cal.add(Calendar.DAY_OF_YEAR,1);}
        ((ActivityChart)a.findViewById(R.id.forwarding_chart)).values(values,labels);
        label(R.id.chart_summary,total==0?"No deliveries recorded in this period.":total+" messages delivered in the last "+days+" days.");
        boolean sms=Build.VERSION.SDK_INT<23||a.checkSelfPermission(android.Manifest.permission.RECEIVE_SMS)==android.content.pm.PackageManager.PERMISSION_GRANTED;
        android.os.PowerManager pm=(android.os.PowerManager)a.getSystemService(Context.POWER_SERVICE);
        boolean battery=Build.VERSION.SDK_INT<23||(pm!=null&&pm.isIgnoringBatteryOptimizations(a.getPackageName()));
        boolean notif=Build.VERSION.SDK_INT<33||a.checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS)==android.content.pm.PackageManager.PERMISSION_GRANTED;
        label(R.id.setup_summary,check(Prefs.configured(a),"Device key saved")+"\n"+check(Prefs.lastOkAt(a)>0,"Gateway has accepted a request")+"\n"+check(sms,"Payment message permission")+"\n"+check(battery,"Unrestricted background access")+"\n"+check(notif,"Listener notifications"));
        label(R.id.diagnostics_text,diagnostics());
        label(R.id.queue_summary,box.waiting()+" pending · "+(Prefs.paused(a)?"Forwarding paused":"Oldest messages are sent first")+"\nShowing up to 25 pending messages.");
        List<Outbox.Item> items=box.pending(25);StringBuilder sig=new StringBuilder();for(Outbox.Item item:items)sig.append(item.id).append(':').append(item.attempts).append(';');
        if(!sig.toString().equals(queueSignature)||((LinearLayout)a.findViewById(R.id.queue_rows)).getChildCount()==0){
            queueSignature=sig.toString();LinearLayout list=a.findViewById(R.id.queue_rows);list.removeAllViews();
            if(items.isEmpty())addQueueRow(list,"Queue is clear","New payment messages will appear here while awaiting acknowledgement.");
            for(Outbox.Item item:items)addQueueRow(list,"Message #"+item.id,"Received "+new SimpleDateFormat("d MMM HH:mm",Locale.getDefault()).format(new Date(item.sentAt))+"\nFailed attempts: "+item.attempts+" · SIM "+(item.sim<0?"unknown":item.sim+1));
        }
    }
    private String check(boolean ready,String title){return (ready?"✓  ":"○  ")+title;}
    private void addQueueRow(LinearLayout list,String title,String detail){
        TextView row=new TextView(a);row.setText(title+"\n"+detail);row.setTextColor(a.getResources().getColor(R.color.ink));row.setTextSize(14);int pad=(int)(16*a.getResources().getDisplayMetrics().density);row.setPadding(pad,pad,pad,pad);row.setBackgroundResource(R.drawable.card_background);LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(-1,-2);lp.topMargin=pad/2;list.addView(row,lp);
    }
}
