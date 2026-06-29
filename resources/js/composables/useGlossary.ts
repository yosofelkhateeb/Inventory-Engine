export const glossary: Record<string, { term: string; definition: string }> = {
    abc_classification: {
        term: 'ABC Classification',
        definition: 'Ranks SKUs by their share of total sales revenue over 90 days. A = top 70% of revenue, B = next 20%, C = bottom 10%.',
    },
    xyz_classification: {
        term: 'XYZ Classification',
        definition: 'Ranks SKUs by demand variability over 90 days using the coefficient of variation (stddev ÷ mean). X = stable (CV < 0.5), Y = variable (CV 0.5–1.0), Z = erratic (CV > 1.0). Drives the safety stock multiplier.',
    },
    abc_xyz_classification: {
        term: 'ABC·XYZ Classification',
        definition: 'Combined two-axis SKU classification. ABC ranks revenue share over 90 days (A = top 70%, B = next 20%, C = bottom 10%). XYZ ranks demand variability via coefficient of variation (X = stable, Y = variable, Z = erratic). The pairing (e.g. A·X, C·Z) drives the safety stock multiplier and review cadence.',
    },
    abc_xyz_ax: {
        term: 'ABC-XYZ: A·X',
        definition: 'High revenue, stable demand. Trust the engine fully. Highest priority SKU.',
    },
    abc_xyz_ay: {
        term: 'ABC-XYZ: A·Y',
        definition: 'High revenue, variable demand. Moderate buffer — monitor weekly.',
    },
    abc_xyz_az: {
        term: 'ABC-XYZ: A·Z',
        definition: 'High revenue, erratic demand. Large safety buffer — most dangerous SKU type.',
    },
    abc_xyz_bx: {
        term: 'ABC-XYZ: B·X',
        definition: 'Mid revenue, stable demand. Standard engine logic, routine monitoring.',
    },
    abc_xyz_by: {
        term: 'ABC-XYZ: B·Y',
        definition: 'Mid revenue, variable demand. Slightly elevated safety stock.',
    },
    abc_xyz_bz: {
        term: 'ABC-XYZ: B·Z',
        definition: 'Mid revenue, erratic demand. Fixed buffer — review manually if flagged.',
    },
    abc_xyz_cx: {
        term: 'ABC-XYZ: C·X',
        definition: 'Low revenue, stable demand. Minimal safety stock, low review frequency.',
    },
    abc_xyz_cy: {
        term: 'ABC-XYZ: C·Y',
        definition: 'Low revenue, variable demand. Low priority — watch for trend changes.',
    },
    abc_xyz_cz: {
        term: 'ABC-XYZ: C·Z',
        definition: 'Low revenue, erratic demand. Consider discontinuing — sporadic demand, low value.',
    },
    avg_days_cover: {
        term: 'Avg Days of Cover',
        definition: 'Fleet-wide average of how many days of stock remain across all SKUs.',
    },
    constrained_qty: {
        term: 'Constrained Qty',
        definition: 'Recommended order quantity after applying MOQ rules and budget limits.',
    },
    days_cover: {
        term: 'Days of Cover',
        definition: 'How many days the current effective position will last at the forecast demand rate.',
    },
    recommendation: {
        term: 'Recommendation',
        definition: 'Engine output per SKU — what the engine recommends doing today: Order Now, Watch, Hold, or Budget Blocked. Operators acknowledge, act on, or ignore each one.',
    },
    urgency: {
        term: 'Urgency',
        definition: 'How soon the SKU will stock out. Severe = already past lead time, High = under 3 days of slack, Medium = under 7 days of slack, Low = a week or more of runway. Driven by buffer = days of cover − lead time.',
    },
    buffer: {
        term: 'Buffer',
        definition: 'Days of slack between current cover and supplier lead time. Negative buffer (e.g. −3d) means the SKU will be out of stock 3 days before the next order arrives.',
    },
    order_soon: {
        term: 'Order Soon',
        definition: 'Recommendation tier where the engine recommends an order this week, but stock cover is still above lead time so there is runway to plan the order.',
    },
    watchlist: {
        term: 'Watchlist',
        definition: 'Recommendation tier where stock is healthy but trending toward the reorder point. Monitor only — no action this week.',
    },
    demand_rate: {
        term: 'Demand Rate',
        definition: 'Predicted daily sales rate based on recent sales history.',
    },
    effective_position: {
        term: 'Effective Position',
        definition: 'On-hand stock + in-transit − reserved: the true usable inventory available to meet demand.',
    },
    forecast_demand: {
        term: 'Forecast Demand',
        definition: 'Predicted daily sales rate based on recent sales history using the ML pipeline.',
    },
    in_transit: {
        term: 'In Transit',
        definition: 'Units ordered from a supplier that have been dispatched but not yet received.',
    },
    lead_time: {
        term: 'Lead Time',
        definition: 'Expected number of days from placing an order to receiving it at the warehouse.',
    },
    mae: {
        term: 'MAE',
        definition: 'Mean Absolute Error — average magnitude of forecast errors in units per day. Lower is better.',
    },
    moq: {
        term: 'MOQ',
        definition: 'Minimum Order Quantity — the smallest quantity a supplier will accept on a single order.',
    },
    on_hand: {
        term: 'On Hand',
        definition: 'Physical stock currently held at the warehouse, before accounting for in-transit or reservations.',
    },
    order_now: {
        term: 'Order Now',
        definition: 'Recommendation tier where stock cover has dropped below the supplier lead time — by the time the order arrives, the SKU will be out. Order today.',
    },
    reorder_point: {
        term: 'Reorder Point',
        definition: 'The inventory level at which a replenishment order should be triggered to avoid stockout.',
    },
    reserved: {
        term: 'Reserved',
        definition: 'Units allocated to pending customer orders that are not available to fulfil new demand.',
    },
    safety_stock: {
        term: 'Safety Stock',
        definition: 'Buffer stock held to absorb unexpected demand spikes or supplier lead time variability.',
    },
    smape: {
        term: 'sMAPE',
        definition: 'Symmetric Mean Absolute Percentage Error — forecast accuracy as a percentage. Lower is better.',
    },
    wmape: {
        term: 'Portfolio WMAPE',
        definition: 'Weighted MAPE across all SKUs — lower is better. Measures overall forecast quality.',
    },
    watch: {
        term: 'Watch',
        definition: 'Engine recommendation value for an SKU on the Watchlist tier — stock approaching the reorder point. Monitor daily and prepare to order.',
    },
    hold: {
        term: 'Hold',
        definition: 'Sufficient stock on hand. No action required.',
    },
};

export function getDefinition(key: string): string | undefined {
    return glossary[key]?.definition;
}
