# 2026-10-22 M5 bearish rejection case

Status: retrospective research example; not a live rule and not evidence of profitability.

## Question

Why did the rebound from the session low stall around XAUUSDm 4097-4103, and what did the synchronized GC M5 footprint show before price moved lower?

The screenshot's manual sell entry is approximately 4097.58. Times below are normalized UTC; East Africa Time is UTC+3.

## Observed sequence

| UTC / EAT | XAUUSDm broker candle | GC footprint | Reading available at the close |
|---|---|---|---|
| 05:50 / 08:50 | O 4071.99, H 4075.07, L 4069.75, C 4073.80 | delta +24, volume 772 | Price stabilizes after the low. No directional footprint stack. |
| 05:55 / 08:55 | O 4073.76, H 4090.15, L 4072.51, C 4089.69 | delta +348, volume 1,640 | Strong rebound with aggressive buying; this is initiative buying, not bearish confirmation. |
| 06:00 / 09:00 | O 4089.64, H 4091.51, L 4086.89, C 4090.18 | delta +30, volume 1,084 | Upward progress slows after the impulse. |
| 06:05 / 09:05 | O 4090.29, H 4097.00, L 4089.95, C 4091.99 | delta +128, volume 1,416 | Buyers lift price, but the candle closes far below its high. This is the clearest early evidence of buy effort meeting passive selling. It is not final confirmation because later bars exceed this high. |
| 06:10 / 09:10 | O 4091.98, H 4099.87, L 4091.82, C 4097.18 | delta +110, volume 888 | Buyers make another advance. The sell idea remains unconfirmed. |
| 06:15 / 09:15 | O 4097.13, H 4101.92, L 4096.43, C 4101.00 | delta +4, volume 1,016 | Price reaches a new rebound high with almost no net aggressive-buy advantage. Buying participation has weakened. |
| 06:20 / 09:20 | O 4101.12, H 4102.91, L 4096.85, C 4098.63 | delta +7, volume 755 | Price probes higher and closes bearish. GC prints sell imbalances near the top, but no three-level sell stack. This is the completed rejection candle. |
| 06:25 / 09:25 | O 4098.39, H 4098.44, L 4091.43, C 4091.69 | delta -194, volume 834 | Sellers take control and price breaks lower. This is follow-through confirmation rather than absorption itself. |

## Total participation and total delta

For this study, market participation is the total executed volume in each M5 bar. Total delta is recorded beside it as the net aggressive pressure. Ask and bid totals are not treated as counts of market participants.

| EAT | Total volume | Total delta | Participation vs prior 20-bar median | Absorption process |
|---|---:|---:|---|---|
| 08:55 | 1,640 | +348 | **increased · 3.45x** | High participation drives the rebound. Buying effort produces a large upward result. |
| 09:00 | 1,084 | +30 | **increased · 2.28x** | Participation remains high, but total delta and upward progress contract sharply. |
| 09:05 | 1,416 | +128 | **increased · 2.77x** | Heavy participation and positive delta reach 4097.00, but price closes at 4091.99. This is the first clear effort-versus-result absorption clue. |
| 09:10 | 888 | +110 | **increased · 1.59x** | Another active buying attempt advances price, so a sell is not confirmed. |
| 09:15 | 1,016 | +4 | **increased · 1.50x** | Increased participation produces almost zero total delta while price tests higher. The auction is becoming balanced at the top. |
| 09:20 | 755 | +7 | normal · 0.98x | Participation returns to normal, delta stays nearly flat, and the candle rejects the high. This is the completed rejection. |
| 09:25 | 834 | -194 | normal · 1.09x | Negative total delta and a large bearish close confirm that sellers have taken control. |
| 09:30 | 585 | +29 | normal · 0.73x | A lower-participation response fails to undo the breakdown. |
| 09:35 | 629 | -155 | normal · 0.78x | Negative total delta confirms continued selling pressure. |

From 08:55 through the 09:20 rejection, total volume was 6,799 and cumulative delta was +627. The important deduction is not a buyer-versus-seller participant count. It is that substantial overall participation and positive total delta eventually stopped producing durable upward progress.

From 09:25 through 09:35, total volume was 2,048 and cumulative delta was -320. Participation was normal rather than exceptional, while total delta and price direction aligned lower.

GC POC also migrated upward during the rebound from 4104.4 to 4117.2, then shifted down to 4110.6 on the 09:25 breakdown. This shows value being accepted progressively higher during the rally and then repriced lower when the bearish move was confirmed. GC POC levels must not be treated as identical XAUUSDm execution prices.

## Interpretation

The final top is not a textbook single-bar absorption print with exceptionally large positive delta. It is better described as a multi-bar bearish rejection:

1. Strong initiative buying drives the rebound.
2. Positive delta at 06:05 fails to hold the candle high, showing possible passive selling.
3. Price makes another high, but delta contracts from +110 to +4 and +7.
4. The 06:20 bar rejects the high while sell imbalances appear near the top.
5. The 06:25 bar confirms control with delta -194 and a large bearish close.

This is evidence of failed buying followed by seller confirmation. It cannot identify the traders' motives or prove that absorption caused the reversal.

## Relationship to current rules

- The 4097-4103 reaction area was not an active confirmed H1 supply zone in the application's zone set at the time of review. It behaves like intraday resistance/a retest area in the screenshot, so `zone_absorption_v2` rejects it for location even though its M5 sequence is the model for the new absorption trigger.
- No unique three-level bearish footprint stack printed on the decisive 06:20 rejection candle. A rule requiring that stack would also reject the case.
- The existing body/range absorption proxy does not represent the sequence. It ignores where footprint volume printed and can attach the wrong direction to positive or negative delta.

## Frozen candidate rule to test

Treat this only as one candidate pattern:

1. Price returns to a pre-existing resistance/supply level defined before the M5 signal.
2. An M5 candle tests the level with positive delta but closes materially away from its high.
3. A later M5 retest fails to hold above the level while delta is weaker than on the first attempt.
4. A completed M5 candle closes below the rejection candle's low or another pre-declared structure level with negative delta.

The inverse applies to bullish demand cases. Thresholds for "materially away" and "weaker" must be fixed from a development sample before evaluating later cases.

## Data limitations

- GC futures provides the order-flow footprint; XAUUSDm provides execution-chart prices. They are aligned by bar time, not identical price levels.
- The earliest archived footprints were first observed late after the bars closed. Their final closed values are useful for retrospective pattern research, but not for proving what the live system knew at each earlier moment.
- This is a selected successful-looking example. It must be tested alongside failed and neutral examples.
