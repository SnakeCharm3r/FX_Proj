"""
redis_publisher.py
──────────────────
Pushes tick data and completed bars to Redis.
Laravel reads these keys directly via OrderflowMetricsService.

Redis key layout:
  xauusd:tick:latest          → JSON string of latest tick
  xauusd:bars:{tf}            → Redis list, LPUSH (newest first), trimmed to 500
  xauusd:bars:live:{tf}       → JSON string of current open (incomplete) bar
"""

import json
import logging
import os
import redis

logger = logging.getLogger(__name__)


class RedisPublisher:
    def __init__(self):
        self._client = redis.Redis(
            host=os.getenv("REDIS_HOST", "127.0.0.1"),
            port=int(os.getenv("REDIS_PORT", 6379)),
            password=os.getenv("REDIS_PASSWORD") or None,
            decode_responses=True,
            socket_connect_timeout=5,
            socket_timeout=5,
            retry_on_timeout=True,
        )
        self._bar_list_max = 500

    def ping(self) -> bool:
        try:
            return self._client.ping()
        except redis.RedisError as e:
            logger.error(f"Redis ping failed: {e}")
            return False

    def publish_tick(self, tick: dict):
        """Store latest tick — overwritten every tick."""
        try:
            self._client.set("xauusd:tick:latest", json.dumps({
                "bid":       tick["bid"],
                "ask":       tick["ask"],
                "mid":       tick["mid"],
                "spread":    tick["spread"],
                "direction": tick["direction"],
                "ts":        int(__import__("time").time()),
            }))
        except redis.RedisError as e:
            logger.warning(f"Redis tick publish failed: {e}")

    def publish_closed_bar(self, bar: dict):
        """
        Push a completed bar onto the front of the list for its timeframe.
        Laravel reads: Redis::lrange("xauusd:bars:{tf}", 0, 199)
        """
        tf  = bar["tf"]
        key = f"xauusd:bars:{tf}"
        try:
            pipe = self._client.pipeline()
            pipe.lpush(key, json.dumps(bar))
            pipe.ltrim(key, 0, self._bar_list_max - 1)
            pipe.execute()
            logger.debug(f"Bar published: {tf} O={bar['open']} C={bar['close']} Δ={bar['delta']}")
        except redis.RedisError as e:
            logger.warning(f"Redis bar publish failed [{tf}]: {e}")

    def publish_live_bar(self, tf: str, bar: dict):
        """Overwrite the current open bar snapshot (for real-time dashboard)."""
        try:
            self._client.set(f"xauusd:bars:live:{tf}", json.dumps(bar))
        except redis.RedisError as e:
            logger.warning(f"Redis live bar publish failed [{tf}]: {e}")

    def get_bar_count(self, tf: str) -> int:
        try:
            return self._client.llen(f"xauusd:bars:{tf}")
        except redis.RedisError:
            return 0

    def flush_bars(self, tf: str):
        """Clear all bars for a timeframe (used on restart/reconnect)."""
        try:
            self._client.delete(f"xauusd:bars:{tf}")
        except redis.RedisError:
            pass
