package com.ispledger.paymentapp;

import android.content.Context;
import android.graphics.*;
import android.util.AttributeSet;
import android.view.View;
import java.util.Arrays;

/** Accessible daily delivery chart backed exclusively by local acknowledged deliveries. */
public final class ActivityChart extends View {
    private int[] counts=new int[0];private String[] labels=new String[0];
    private final Paint p=new Paint(Paint.ANTI_ALIAS_FLAG);
    public ActivityChart(Context c,AttributeSet attrs){super(c,attrs);setImportantForAccessibility(IMPORTANT_FOR_ACCESSIBILITY_YES);}
    void values(int[] values,String[] names){
        if(Arrays.equals(counts,values)&&Arrays.equals(labels,names))return;
        counts=values;labels=names;StringBuilder description=new StringBuilder("Delivered messages by day. ");
        for(int i=0;i<values.length;i++)description.append(names[i]).append(": ").append(values[i]).append(". ");
        setContentDescription(description);invalidate();
    }
    @Override protected void onDraw(Canvas c){
        super.onDraw(c);if(counts.length==0)return;
        float d=getResources().getDisplayMetrics().density,left=28*d,bottom=getHeight()-28*d,top=24*d,width=getWidth()-left-8*d;
        int max=1;for(int value:counts)max=Math.max(max,value);
        p.setTextSize(10*getResources().getDisplayMetrics().scaledDensity);p.setStrokeWidth(d);
        for(int i=0;i<3;i++){float y=top+(bottom-top)*i/2;p.setColor(Color.rgb(220,229,213));c.drawLine(left,y,getWidth()-8*d,y,p);}
        p.setColor(Color.rgb(97,116,100));c.drawText(String.valueOf(max),0,top+4*d,p);c.drawText("0",0,bottom+3*d,p);
        float step=width/counts.length;
        for(int i=0;i<counts.length;i++){
            float x=left+step*i+step*.18f,h=(bottom-top)*counts[i]/max;
            p.setColor(i==counts.length-1?Color.rgb(8,102,83):Color.rgb(139,185,162));
            if(counts[i]>0)c.drawRoundRect(x,bottom-h,x+step*.64f,bottom,3*d,3*d,p);
            p.setColor(Color.rgb(97,116,100));
            if(i==0||i==counts.length-1){p.setTextAlign(i==0?Paint.Align.LEFT:Paint.Align.RIGHT);c.drawText(labels[i],i==0?left:getWidth()-8*d,getHeight()-7*d,p);p.setTextAlign(Paint.Align.LEFT);}
        }
    }
}
