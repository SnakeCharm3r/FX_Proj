"""Build a local adapter from the user-provided vendor developer source.
Vendor source is kept in the archive; the generated derivative preserves attribution.
"""
import argparse
from pathlib import Path
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument('--archive', type=Path, default=Path('/home/simon/Downloads/ClusterDelta_MT_Sources.zip'))
parser.add_argument('--output', type=Path, default=Path('/tmp/fx-clusterdelta-build/FXClusterDeltaFootprint.mq5'))
args = parser.parse_args()
with zipfile.ZipFile(args.archive) as archive:
    raw = archive.read('MQL5/Indicators/#Footprint_Data_for_EA.mq5')
source = raw.decode('utf-16') if raw.startswith(b'\xff\xfe') else raw.decode('cp1252')
source = source.replace('\r\n', '\n')
a = source.index('void Main_Func()')
b = source.index('void GetClusters()', a)
source = source[:a] + 'void Main_Func() { ExportFXFootprint(); }\n\n' + source[b:]
source = source.replace('#include <tools/DateTime.mqh>', '#include <Tools/DateTime.mqh>')
source = source.replace('return TerminalInfoInteger(TERMINAL_DLLS_ALLOWED);', 'return MQLInfoInteger(MQL_DLLS_ALLOWED);')
source = source.replace('input bool ONLINE_STREAM=false;', 'input bool ONLINE_STREAM=true;')
source = source.replace('input ChartFutures ChartInstrument=·AUTO;', 'input ChartFutures ChartInstrument=·GC·XAUUSD·Gold;')
source = source.replace('input string MetaTrader_GMT="AUTO";', 'input string MetaTrader_GMT="0";')
source = source.replace('   Main_Cycle();', '   Main_Cycle();\n   ExportFXFootprint();')
source += '\n' + Path(__file__).with_name('FootprintExport.mqh').read_text()
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(source, encoding='utf-16')
print('Generated', args.output)
