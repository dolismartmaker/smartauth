<?php
/**
 * RedisRateLimiter.php
 *
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


namespace SmartAuth\Api;


class RedisRateLimiter
{
    private $redis;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect(
            getDolGlobalString('SMARTAUTH_REDIS_HOST', '127.0.0.1'),
            getDolGlobalInt('SMARTAUTH_REDIS_PORT', 6379)
        );
    }

    public function checkLimit($identifier, $action, $max_attempts, $window_seconds)
    {
        $key = "ratelimit:{$action}:{$identifier}";
        $current = $this->redis->incr($key);

        if ($current == 1) {
            $this->redis->expire($key, $window_seconds);
        }

        if ($current > $max_attempts) {
            $ttl = $this->redis->ttl($key);
            return ['allowed' => false, 'retry_after' => $ttl];
        }

        return ['allowed' => true, 'retry_after' => null];
    }

    public function reset($identifier, $action)
    {
        $key = "ratelimit:{$action}:{$identifier}";
        $this->redis->del($key);
    }
}
