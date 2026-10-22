// Local FX Project adapter. Appended to the supplied ClusterDelta source.
input int FX_ExportBars=12;
input int FX_ExportSeconds=10;
datetime FX_LastExport=0;

string FX_JsonString(string value)
{
   StringReplace(value,"\\","\\\\");
   StringReplace(value,"\"","\\\"");
   StringReplace(value,"\r","");
   StringReplace(value,"\n"," ");
   return "\""+value+"\"";
}

void ExportFXFootprint()
{
   if(TimeLocal()-FX_LastExport<MathMax(8,FX_ExportSeconds)) return;
   if(IDX<0 || Workspace.TickSize<=0 || FuturesName!="GC") return;
   // Request offset 0; the app retains and normalizes the observed vendor clock.
   if(MetaTrader_GMT!="0") { Print("FX footprint: keep MetaTrader_GMT=0 for UTC export"); return; }
   int minutes=(int)StringToInteger(Workspace.CURRENT_TIMEFRAME);
   string tf="";
   switch(minutes) {
      case 1: tf="1m"; break; case 5: tf="5m"; break;
      case 15: tf="15m"; break; case 30: tf="30m"; break;
      case 60: tf="1h"; break; case 240: tf="4h"; break;
      case 1440: tf="1d"; break; default: return;
   }
   string bars="";
   int exported=0;
   for(int q=MathMax(0,IDX-MathMin(40,MathMax(1,FX_ExportBars))+1);q<=IDX;q++) {
      int i=ClusterINDEX(q);
      if(i<0 || i>=ArraySize(Clusters)) continue;
      string levels="";
      long totalAsk=0,totalBid=0;
      int rows=0;
      for(int k=0;k<ArraySize(Clusters[i].prices);k++) {
         double price=Clusters[i].prices[k];
         if(price==0) break;
         long ask=Clusters[i].ask[k],bid=Clusters[i].bid[k];
         if(price<0 || ask<0 || bid<0) return;
         if(rows>=5000) { Print("FX footprint: too many price rows; snapshot skipped"); return; }
         if(rows>0) levels+=",";
         levels+=StringFormat("{\"price\":%s,\"ask\":%I64d,\"bid\":%I64d}",DoubleToString(price,DigitsAfterComa),ask,bid);
         totalAsk+=ask; totalBid+=bid; rows++;
      }
      if(rows==0) continue;
      if(exported>0) bars+=",";
      bool closed=(long)Clusters[i].opentime+minutes*60<=(long)TimeGMT();
      bars+=StringFormat("{\"time\":%I64d,\"closed\":%s,\"open\":%s,\"high\":%s,\"low\":%s,\"close\":%s,\"ask\":%I64d,\"bid\":%I64d,\"reported_ask\":%I64d,\"reported_bid\":%I64d,\"delta_min\":%I64d,\"delta_max\":%I64d,\"levels\":[%s]}",
         (long)Clusters[i].opentime,closed?"true":"false",
         DoubleToString(Clusters[i].open,DigitsAfterComa),DoubleToString(Clusters[i].high,DigitsAfterComa),
         DoubleToString(Clusters[i].low,DigitsAfterComa),DoubleToString(Clusters[i].close,DigitsAfterComa),
         totalAsk,totalBid,Clusters[i].total_ask,Clusters[i].total_bid,
         Clusters[i].delta_min,Clusters[i].delta_max,levels);
      exported++;
   }
   if(exported==0) return;
   string payload="{\"instrument\":\"GC\",\"tf\":\""+tf+"\",\"symbol\":"+FX_JsonString(_Symbol)+
      ",\"vendor_expiration\":"+FX_JsonString(Expiration)+",\"tick_size\":"+DoubleToString(Workspace.TickSize,DigitsAfterComa)+
      ",\"bars\":["+bars+"]}";
   string target="FXFootprint_GC_"+tf+".json";
   string temporary=target+"."+clusterdelta_client+".tmp";
   int file=FileOpen(temporary,FILE_WRITE|FILE_TXT|FILE_ANSI|FILE_COMMON,0,CP_UTF8);
   if(file==INVALID_HANDLE) { Print("FX footprint: file open failed ",GetLastError()); return; }
   FileWriteString(file,payload); FileClose(file);
   if(!FileMove(temporary,FILE_COMMON,target,FILE_COMMON|FILE_REWRITE)) {
      Print("FX footprint: publish failed ",GetLastError()); return;
   }
   FX_LastExport=TimeLocal();
   Print("FX footprint: exported ",exported," GC ",tf," clusters with native bid/ask price cells");
}
