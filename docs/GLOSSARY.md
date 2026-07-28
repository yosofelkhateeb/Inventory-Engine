# Glossary

Every metric and label in the app has an inline definition (the `?` tooltips and the
in-app glossary panel). This is the full reference, grouped by area.

## SKU classification (ABC / XYZ)

| Term | Definition |
|---|---|
| ABC Classification | Ranks SKUs by their share of total sales revenue over 90 days. A = top 70% of revenue, B = next 20%, C = bottom 10%. |
| XYZ Classification | Ranks SKUs by demand variability over 90 days using the coefficient of variation (stddev ÷ mean). X = stable (CV < 0.5), Y = variable (CV 0.5–1.0), Z = erratic (CV > 1.0). Drives the safety stock multiplier. |
| ABC·XYZ Classification | Combined two-axis SKU classification. ABC ranks revenue share over 90 days (A = top 70%, B = next 20%, C = bottom 10%). XYZ ranks demand variability via coefficient of variation (X = stable, Y = variable, Z = erratic). The pairing (e.g. A·X, C·Z) drives the safety stock multiplier and review cadence. |
| ABC-XYZ: A·X | High revenue, stable demand. Trust the engine fully. Highest priority SKU. |
| ABC-XYZ: A·Y | High revenue, variable demand. Moderate buffer — monitor weekly. |
| ABC-XYZ: A·Z | High revenue, erratic demand. Large safety buffer — most dangerous SKU type. |
| ABC-XYZ: B·X | Mid revenue, stable demand. Standard engine logic, routine monitoring. |
| ABC-XYZ: B·Y | Mid revenue, variable demand. Slightly elevated safety stock. |
| ABC-XYZ: B·Z | Mid revenue, erratic demand. Fixed buffer — review manually if flagged. |
| ABC-XYZ: C·X | Low revenue, stable demand. Minimal safety stock, low review frequency. |
| ABC-XYZ: C·Y | Low revenue, variable demand. Low priority — watch for trend changes. |
| ABC-XYZ: C·Z | Low revenue, erratic demand. Consider discontinuing — sporadic demand, low value. |

## Recommendations & urgency

| Term | Definition |
|---|---|
| Recommendation | Engine output per SKU — what the engine recommends doing today: Order Now, Watch, Hold, or Budget Blocked. Operators acknowledge, act on, or ignore each one. |
| Order Now | Recommendation tier where stock cover has dropped below the supplier lead time — by the time the order arrives, the SKU will be out. Order today. |
| Order Soon | Recommendation tier where the engine recommends an order this week, but stock cover is still above lead time so there is runway to plan the order. |
| Watch | Engine recommendation value for an SKU on the Watchlist tier — stock approaching the reorder point. Monitor daily and prepare to order. |
| Watchlist | Recommendation tier where stock is healthy but trending toward the reorder point. Monitor only — no action this week. |
| Hold | Sufficient stock on hand. No action required. |
| Urgency | How soon the SKU will stock out. Severe = already past lead time, High = under 3 days of slack, Medium = under 7 days of slack, Low = a week or more of runway. Driven by buffer = days of cover − lead time. |
| Buffer | Days of slack between current cover and supplier lead time. Negative buffer (e.g. −3d) means the SKU will be out of stock 3 days before the next order arrives. |

## Inventory position

| Term | Definition |
|---|---|
| On Hand | Physical stock currently held at the warehouse, before accounting for in-transit or reservations. |
| In Transit | Units ordered from a supplier that have been dispatched but not yet received. |
| Reserved | Units allocated to pending customer orders that are not available to fulfil new demand. |
| Effective Position | On-hand stock + in-transit − reserved: the true usable inventory available to meet demand. |
| Days of Cover | How many days the current effective position will last at the forecast demand rate. |
| Avg Days of Cover | Fleet-wide average of how many days of stock remain across all SKUs. |
| Reorder Point | The inventory level at which a replenishment order should be triggered to avoid stockout. |
| Safety Stock | Buffer stock held to absorb unexpected demand spikes or supplier lead time variability. |
| MOQ | Minimum Order Quantity — the smallest quantity a supplier will accept on a single order. |
| Constrained Qty | Recommended order quantity after applying MOQ rules and budget limits. |
| Lead Time | Expected number of days from placing an order to receiving it at the warehouse. |

## Forecasting metrics

| Term | Definition |
|---|---|
| Demand Rate | Predicted daily sales rate based on recent sales history. |
| Forecast Demand | Predicted daily sales rate based on recent sales history using the ML pipeline. |
| MAE | Mean Absolute Error — average magnitude of forecast errors in units per day. Lower is better. |
| sMAPE | Symmetric Mean Absolute Percentage Error — forecast accuracy as a percentage. Lower is better. |
| Portfolio WMAPE | Weighted MAPE across all SKUs — lower is better. Measures overall forecast quality. |
