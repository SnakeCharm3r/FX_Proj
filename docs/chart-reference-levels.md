# Chart VWAP and POC estimates

The chart and metric cards now share `BrokerReferenceService`. Both use the latest 20 candles, including the developing candle. Chart points use their own trailing windows; a later candle does not rewrite the calculation at an earlier timestamp.

The previous chart used every cached candle (127 M15 candles at diagnosis), while the cards used 20. Consequently the chart showed VWAP around 4125.86 and a dominant close-volume bucket at 4123, while the cards showed about 4129.30 and 4138. The chart also divided an already string-cast price by two, creating float array keys; PHP truncated half-point prices when using those keys. Integer half-point indices now preserve prices ending in .5.

- **VWAP proxy (20):** sum of `(high + low + close) / 3 × exported volume`, divided by volume over the trailing 20 candles. This is a broker-price estimate weighted by exchange bar volume, not a tick-accurate session VWAP or imported vendor VWAP.
- **POC proxy (20):** the half-point broker-close bucket with the most exported bar volume over the trailing 20 candles. It uses whole-candle volume assigned to the candle close, not actual volume traded at that broker price. The chart now plots its rolling history rather than projecting one cache-wide level across the entire chart.

A POC estimate can remain unchanged while price and VWAP move. It changes when another close bucket leads or an old candle leaves the window. Zero-volume windows have no chart reference point. Unavailable timeframes clear the reference lines and legend instead of retaining another timeframe's values.

The real GC candle POC is separately computed from footprint price cells and remains available in the Footprint view. Native futures levels are not drawn as broker XAUUSD prices. The setup engine's GC POC rejection continues using native footprint levels; this chart repair does not change it. The strategy's existing 20-completed-candle VWAP calculation is preserved.

Verification: 92 tests / 538 assertions passed. Coverage includes half-point precision, zero volume, dropping old dominant bars from the rolling window, developing-volume updates, stable POC behavior, no historical point rewriting and chart/card agreement. The live M15 endpoints returned matching VWAP 4129.48 and POC proxy 4138 in the same received snapshot at verification; these values change with market data.
