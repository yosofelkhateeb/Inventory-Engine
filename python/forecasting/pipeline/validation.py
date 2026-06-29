"""
Stage 6: Time-Aware Walk-Forward Expanding Window Cross-Validation

Creates fold boundaries for fair model comparison.
Hard rule: no random splits. No full-dataset scaling before split.
Recompute train-dependent objects inside each fold.

Returns fold list consumed by evaluator.py, baselines.py, and model training.
"""
import logging

import numpy as np
import pandas as pd

from pipeline._config import load_config

logger = logging.getLogger(__name__)


def create_folds(full_series: pd.Series) -> list[dict]:
    """
    Build walk-forward (expanding window) CV fold specifications.

    Each fold dict contains:
        fold_num     : int
        train_end_idx: int   — inclusive index into full_series
        val_start_idx: int
        val_end_idx  : int   — inclusive
        train_start  : str   — date string
        train_end    : str
        val_start    : str
        val_end      : str

    The last `holdout_days` of the series are NEVER included in any fold.
    They are the held-out test set.

    Returns [] when insufficient history for even a single fold (degraded path).
    """
    cfg        = load_config()
    n_folds    = int(cfg["validation"]["cv_folds"])
    min_folds  = int(cfg["validation"]["min_cv_folds"])
    val_window = int(cfg["validation"]["validation_window_days"])
    min_train  = int(cfg["validation"]["min_training_window_days"])
    holdout    = int(cfg["validation"]["holdout_days"])

    series = full_series.dropna()
    n = len(series)

    # Available days for CV = everything before the held-out test set
    cv_n = n - holdout
    if cv_n < min_train + val_window:
        logger.warning(
            f"Insufficient history for CV ({n} days). "
            f"Using single train/test split."
        )
        return _single_split(series, holdout)

    # ── Build fold boundaries ──────────────────────────────────────────────────
    # Fold k ends its validation window at: cv_n - (n_folds - k - 1) * val_window
    # Minimum train window = min_train

    folds = []
    for k in range(n_folds):
        # Validation window ends at
        val_end_idx   = cv_n - (n_folds - k - 1) * val_window - 1
        val_start_idx = val_end_idx - val_window + 1
        train_end_idx = val_start_idx - 1

        if train_end_idx < min_train - 1:
            continue
        if val_start_idx < 0 or val_end_idx >= n:
            continue

        folds.append({
            "fold_num":      k + 1,
            "train_end_idx": train_end_idx,
            "val_start_idx": val_start_idx,
            "val_end_idx":   val_end_idx,
            "train_start":   str(series.index[0].date()),
            "train_end":     str(series.index[train_end_idx].date()),
            "val_start":     str(series.index[val_start_idx].date()),
            "val_end":       str(series.index[val_end_idx].date()),
        })

    if len(folds) < min_folds:
        logger.warning(
            f"Only {len(folds)} valid folds (min {min_folds}). "
            f"Falling back to single split."
        )
        return _single_split(series, holdout)

    return folds


def get_holdout(full_series: pd.Series) -> tuple[np.ndarray, pd.DatetimeIndex]:
    """
    Return the held-out test set (last holdout_days of the series).
    Not included in any CV fold.
    """
    cfg     = load_config()
    holdout = int(cfg["validation"]["holdout_days"])
    series  = full_series.dropna()
    n       = len(series)
    start   = max(0, n - holdout)
    return series.values[start:].astype(float), series.index[start:]


def _single_split(series: pd.Series, holdout: int) -> list[dict]:
    """Degraded path: single fold using the last holdout days as validation."""
    n = len(series)
    if n < 2:
        return []
    val_end_idx   = n - 1
    val_start_idx = max(1, n - holdout)
    train_end_idx = val_start_idx - 1
    return [{
        "fold_num":      1,
        "train_end_idx": train_end_idx,
        "val_start_idx": val_start_idx,
        "val_end_idx":   val_end_idx,
        "train_start":   str(series.index[0].date()),
        "train_end":     str(series.index[train_end_idx].date()),
        "val_start":     str(series.index[val_start_idx].date()),
        "val_end":       str(series.index[val_end_idx].date()),
        "degraded":      True,
    }]
