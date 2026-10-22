#property strict
#property version "1.04"
// Reads the supplied vendor indicator. This EA never places orders.
// A read-only process diagnostic also exposes MT5's Dependencies permission
// on this EA, whose vendor DLL dependency otherwise sits behind iCustom.
#import "kernel32.dll"
uint GetCurrentProcessId();
#import
input string ApiBase = "https://your-fx-app.example/api/clusterdelta";
input string BridgeToken = "";
input bool LocalFileTransport = true;
input string Instrument = "GC";
input int ClusterInstrumentId = 24;
input int BrokerUtcOffsetMinutes = 10000; // 10000: detect broker clock offset; otherwise explicit minutes.
input int PollSeconds = 10;
input bool ExportOpenBar = true; // Include the developing candle on each poll.
input int H1HistoryBars = 500; // Broker price-only history; independent of vendor volume buffers.
input int OrderflowHistoryBars = 3500; // Completed volume/delta bars, split into 500-bar local snapshots.
int handle = INVALID_HANDLE;
string tf;
datetime lastDiagnostic=0;
datetime lastHistoryExport=0;

void Diagnostic(string message)
{
   if(TimeLocal()-lastDiagnostic<60) return;
   lastDiagnostic=TimeLocal();
   Print("FXClusterDeltaBridge: ",message,"; terminal error=",GetLastError());
}

int OnInit()
{
   Print("FXClusterDeltaBridge: starting on ",_Symbol,"; terminal DLL permission=",
      TerminalInfoInteger(TERMINAL_DLLS_ALLOWED),"; EA DLL permission=",MQLInfoInteger(MQL_DLLS_ALLOWED));
   if(!MQLInfoInteger(MQL_DLLS_ALLOWED)) {
      Print("FXClusterDeltaBridge: enable Allow DLL imports in this EA's Dependencies tab and reattach.");
      return INIT_FAILED;
   }
   Print("FXClusterDeltaBridge: terminal process=",GetCurrentProcessId(),
      "; export folder=",TerminalInfoString(TERMINAL_COMMONDATA_PATH),"\\Files");
   switch(_Period) {
      case PERIOD_M1: tf="1m"; break;
      case PERIOD_M5: tf="5m"; break;
      case PERIOD_M15: tf="15m"; break;
      case PERIOD_M30: tf="30m"; break;
      case PERIOD_H1: tf="1h"; break;
      case PERIOD_H4: tf="4h"; break;
      case PERIOD_D1: tf="1d"; break;
      default: return INIT_PARAMETERS_INCORRECT;
   }
   if((!LocalFileTransport && BridgeToken=="") || PollSeconds<8) return INIT_PARAMETERS_INCORRECT;
   // Signature from the archive's DemoVolumes.mq5; live streaming enabled.
   int requestedBars=MathMax(100,MathMin(OrderflowHistoryBars,4000));
   handle=iCustom(_Symbol,_Period,"\\Experts\\#Volume_Delta_EA_iCustom",
      ClusterInstrumentId,"AUTO",requestedBars,true,
      (datetime)(TimeCurrent()-requestedBars*PeriodSeconds(_Period)-86400),
      D'2017.01.01 00:00:00',false);
   if(handle==INVALID_HANDLE) {
      Print("FXClusterDeltaBridge: vendor helper failed to load; terminal error=",GetLastError());
      return INIT_FAILED;
   }
   EventSetTimer(PollSeconds);
   Print("FXClusterDeltaBridge: helper loaded; waiting for ",Instrument," ",tf," buffers.");
   return INIT_SUCCEEDED;
}

void OnDeinit(const int reason)
{
   EventKillTimer();
   if(handle!=INVALID_HANDLE) IndicatorRelease(handle);
}

void OnTimer()
{
   ExportHourlyHistory();
   ExportOrderflowHistory();
   // Resend recent history plus the optional developing bar; timestamps upsert.
   double volume[],delta[];
   datetime times[];
   MqlRates rates[];
   const int count=50;
   const int shift=ExportOpenBar ? 0 : 1;
   datetime currentOpen=iTime(_Symbol,_Period,0);
   // CopyBuffer initiates missing indicator/history calculation. Do not gate it
   // on BarsCalculated: that can leave a lazily loaded indicator waiting forever.
   ResetLastError();
   if(CopyBuffer(handle,0,shift,count,volume)!=count) {
      Diagnostic("volume buffer not ready; calculated bars="+IntegerToString(BarsCalculated(handle)));
      return;
   }
   if(CopyBuffer(handle,2,shift,count,delta)!=count) { Diagnostic("delta buffer not ready"); return; }
   long utcOffset=BrokerUtcOffsetMinutes==10000
      ? (long)MathRound((double)(TimeTradeServer()-TimeGMT())/1800.0)*1800
      : (long)BrokerUtcOffsetMinutes*60;
   if(CopyTime(_Symbol,_Period,shift,count,times)!=count) { Diagnostic("broker candle times not ready"); return; }
   if(CopyRates(_Symbol,_Period,shift,count,rates)!=count) { Diagnostic("broker candles not ready"); return; }
   if(currentOpen!=iTime(_Symbol,_Period,0)) { Diagnostic("candle boundary crossed; retrying next poll"); return; }
   string bars="";
   for(int i=0;i<count;i++) {
      if(!MathIsValidNumber(volume[i]) || !MathIsValidNumber(delta[i]) ||
         volume[i]==EMPTY_VALUE || delta[i]==EMPTY_VALUE ||
         volume[i]<0 || MathAbs(delta[i])>volume[i]) { Diagnostic("vendor returned empty or invalid volume/delta"); return; }
      if(i>0) bars+=",";
      bars+=StringFormat("{\"time\":%I64d,\"volume\":%.0f,\"delta\":%.0f,\"open\":%.8f,\"high\":%.8f,\"low\":%.8f,\"close\":%.8f,\"closed\":%s}",
         (long)times[i]-utcOffset,volume[i],delta[i],rates[i].open,rates[i].high,rates[i].low,rates[i].close,
         (!ExportOpenBar || i<count-1) ? "true" : "false");
   }
   string payload="{\"instrument\":\""+Instrument+"\",\"tf\":\""+tf+"\",\"bars\":["+bars+"]}";
   if(LocalFileTransport) {
      int file=FileOpen("FXClusterDelta_"+Instrument+"_"+tf+".json",FILE_WRITE|FILE_TXT|FILE_ANSI|FILE_COMMON,0,CP_UTF8);
      if(file==INVALID_HANDLE) { Print("FX bridge file error: ",GetLastError()); return; }
      FileWriteString(file,payload);
      FileClose(file);
      Diagnostic("exported 50 bars for "+Instrument+" "+tf+(ExportOpenBar ? " including the open candle" : " (completed only)"));
      return;
   }
   char body[],result[];
   StringToCharArray(payload,body,0,WHOLE_ARRAY,CP_UTF8);
   ArrayResize(body,ArraySize(body)-1);
   string responseHeaders;
   int status=WebRequest("POST",ApiBase+"/bars",
      "Content-Type: application/json\r\nAuthorization: Bearer "+BridgeToken+"\r\n",
      5000,body,result,responseHeaders);
   if(status!=200) Print("FX ClusterDelta bridge HTTP status: ",status,"; terminal error: ",GetLastError());
}

void ExportOrderflowHistory()
{
   if(!LocalFileTransport || OrderflowHistoryBars<=50 || TimeLocal()-lastHistoryExport<3600) return;
   const int count=MathMin(OrderflowHistoryBars,4000);
   double volume[],delta[];
   datetime times[];
   MqlRates rates[];
   ResetLastError();
   if(CopyBuffer(handle,0,1,count,volume)!=count) { Diagnostic("historical volume buffer not ready"); return; }
   if(CopyBuffer(handle,2,1,count,delta)!=count) { Diagnostic("historical delta buffer not ready"); return; }
   if(CopyTime(_Symbol,_Period,1,count,times)!=count) { Diagnostic("historical candle times not ready"); return; }
   if(CopyRates(_Symbol,_Period,1,count,rates)!=count) { Diagnostic("historical broker candles not ready"); return; }
   long utcOffset=BrokerUtcOffsetMinutes==10000
      ? (long)MathRound((double)(TimeTradeServer()-TimeGMT())/1800.0)*1800
      : (long)BrokerUtcOffsetMinutes*60;
   int batch=0;
   for(int start=0;start<count;start+=500) {
      int finish=MathMin(start+500,count);
      string bars="";
      for(int i=start;i<finish;i++) {
         if(!MathIsValidNumber(volume[i]) || !MathIsValidNumber(delta[i]) ||
            volume[i]==EMPTY_VALUE || delta[i]==EMPTY_VALUE ||
            volume[i]<0 || MathAbs(delta[i])>volume[i]) {
            Diagnostic("historical vendor buffer contains an invalid bar"); return;
         }
         if(i>start) bars+=",";
         bars+=StringFormat("{\"time\":%I64d,\"volume\":%.0f,\"delta\":%.0f,\"open\":%.8f,\"high\":%.8f,\"low\":%.8f,\"close\":%.8f,\"closed\":true}",
            (long)times[i]-utcOffset,volume[i],delta[i],rates[i].open,rates[i].high,rates[i].low,rates[i].close);
      }
      string payload="{\"instrument\":\""+Instrument+"\",\"tf\":\""+tf+"\",\"bars\":["+bars+"]}";
      string name="FXClusterDelta_"+Instrument+"_"+tf+"_history_"+IntegerToString(batch)+".json";
      int file=FileOpen(name,FILE_WRITE|FILE_TXT|FILE_ANSI|FILE_COMMON,0,CP_UTF8);
      if(file==INVALID_HANDLE) { Print("FX bridge history file error: ",GetLastError()); return; }
      FileWriteString(file,payload); FileClose(file); batch++;
   }
   lastHistoryExport=TimeLocal();
   Print("FXClusterDeltaBridge: exported ",count," completed historical ",Instrument," ",tf," bars in ",batch," batches");
}

void ExportHourlyHistory()
{
   if(!LocalFileTransport || H1HistoryBars<1) return;
   MqlRates history[];
   int copied=CopyRates(_Symbol,PERIOD_H1,1,MathMin(H1HistoryBars,500),history);
   if(copied<=0) return; // CopyRates requests history asynchronously; retry next timer.
   long offset=BrokerUtcOffsetMinutes==10000
      ? (long)MathRound((double)(TimeTradeServer()-TimeGMT())/1800.0)*1800
      : (long)BrokerUtcOffsetMinutes*60;
   string bars="";
   for(int i=0;i<copied;i++) {
      if(i>0) bars+=",";
      bars+=StringFormat("{\"time\":%I64d,\"open\":%.8f,\"high\":%.8f,\"low\":%.8f,\"close\":%.8f}",
         (long)history[i].time-offset,history[i].open,history[i].high,history[i].low,history[i].close);
   }
   string payload="{\"instrument\":\""+Instrument+"\",\"symbol\":\""+_Symbol+"\",\"tf\":\"1h\",\"bars\":["+bars+"]}";
   int file=FileOpen("FXBrokerHistory_"+Instrument+"_1h.json",FILE_WRITE|FILE_TXT|FILE_ANSI|FILE_COMMON,0,CP_UTF8);
   if(file==INVALID_HANDLE) return;
   FileWriteString(file,payload); FileClose(file);
}
