"""
Shared config loader for pipeline modules.
Loads forecasting_config.yaml once and caches it.
"""
import os
import yaml

_CONFIG: dict | None = None

def load_config() -> dict:
    global _CONFIG
    if _CONFIG is None:
        config_path = os.path.join(
            os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
            "config", "forecasting_config.yaml",
        )
        with open(config_path, "r", encoding="utf-8") as f:
            _CONFIG = yaml.safe_load(f)
    return _CONFIG
