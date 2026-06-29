#!/usr/bin/env python3
"""
Layer 3 of the Layered Hybrid Uplift Prediction Engine.

Reads a Campaign Brief feature vector and a training set of past
Brief-tagged promotions with computed actual lifts, fits LightGBM
quantile regressors at q=0.25/0.50/0.75, and emits a prediction
with confidence band.

Wired in by app/Services/Promotions/Layers/MlLayer.php as a Python
subprocess. The PHP side prepares the training corpus from the
database; this script does not touch the database directly (matches
the existing forecasting microservice pattern).

Input JSON (read from --input <path>):
    {
        "brief": { "features": [<23 floats>] },
        "training_data": [
            { "features": [<23 floats>], "lift": 35.0 },
            ...
        ]
    }

Output JSON (stdout, single line):
    success:
        {
            "value": 32.5,        # median (q=0.5)
            "lower": 18.0,        # q=0.25
            "upper": 55.0,        # q=0.75
            "basis": "ML model trained on N past campaigns",
            "sample_size": N,
            "layer": "ml"
        }
    abstain (insufficient data, missing dependency, or training error):
        {
            "value": 0.0,
            "lower": 0.0,
            "upper": 0.0,
            "basis": "<reason>",
            "sample_size": 0,
            "layer": "ml"
        }

The orchestrator (PredictionEngine) treats sample_size = 0 as
"abstain — fall back to the next layer down."

Convention shared with Layer 1 / Layer 2: predictions clamp to >= 0.
"Expected uplift" should not predict a sales decline.
"""

from __future__ import annotations

import argparse
import json
import sys
import traceback


DEFAULT_MIN_TRAINING_SAMPLES = 50


def empty_result(basis: str) -> dict:
    return {
        "value": 0.0,
        "lower": 0.0,
        "upper": 0.0,
        "basis": basis,
        "sample_size": 0,
        "layer": "ml",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Layer 3 LightGBM uplift predictor")
    parser.add_argument("--input", required=True, help="Path to input JSON file")
    args = parser.parse_args()

    try:
        with open(args.input, "r", encoding="utf-8") as f:
            payload = json.load(f)
    except Exception as e:  # noqa: BLE001 — single defensive boundary
        print(json.dumps(empty_result(f"Failed to read input: {e}")))
        return 0

    brief = payload.get("brief")
    training_data = payload.get("training_data") or []
    # Threshold flows from PHP so the PHP-side `uplift.min_ml_samples` setting
    # is the single source of truth across the language boundary.
    min_samples = int(payload.get("min_samples", DEFAULT_MIN_TRAINING_SAMPLES))

    if not isinstance(brief, dict) or "features" not in brief:
        print(json.dumps(empty_result("Brief missing or malformed")))
        return 0

    if len(training_data) < min_samples:
        print(json.dumps(empty_result(
            f"Insufficient training data ({len(training_data)} < {min_samples})"
        )))
        return 0

    try:
        import lightgbm as lgb
        import numpy as np
    except ImportError as e:
        print(json.dumps(empty_result(f"LightGBM unavailable: {e}")))
        return 0

    try:
        # Build training matrix.
        X_train = np.array([row["features"] for row in training_data], dtype=float)
        y_train = np.array([float(row["lift"]) for row in training_data], dtype=float)

        if X_train.ndim != 2:
            print(json.dumps(empty_result("Training feature shape invalid")))
            return 0

        # Fit three quantile regressors for the prediction band.
        # n_estimators kept modest — the training set is small and we'd
        # rather underfit slightly than chase noise.
        models: dict[float, lgb.LGBMRegressor] = {}
        for q in (0.25, 0.5, 0.75):
            model = lgb.LGBMRegressor(
                objective="quantile",
                alpha=q,
                n_estimators=100,
                num_leaves=15,
                min_data_in_leaf=5,
                verbose=-1,
            )
            model.fit(X_train, y_train)
            models[q] = model

        X_test = np.array([brief["features"]], dtype=float)

        lower = max(0.0, float(models[0.25].predict(X_test)[0]))
        median = max(0.0, float(models[0.5].predict(X_test)[0]))
        upper = max(0.0, float(models[0.75].predict(X_test)[0]))

        # Quantile regressors with small training sets occasionally produce
        # non-monotonic predictions. Re-sort defensively.
        lower, median, upper = sorted([lower, median, upper])

        result = {
            "value": round(median, 1),
            "lower": round(lower, 1),
            "upper": round(upper, 1),
            "basis": f"ML model trained on {len(training_data)} past campaigns",
            "sample_size": len(training_data),
            "layer": "ml",
        }
        print(json.dumps(result))
        return 0
    except Exception as e:  # noqa: BLE001
        traceback.print_exc(file=sys.stderr)
        print(json.dumps(empty_result(f"Training/inference failed: {e}")))
        return 0


if __name__ == "__main__":
    sys.exit(main())
