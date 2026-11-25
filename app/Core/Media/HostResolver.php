<?php

declare(strict_types=1);

namespace App\Core\Media;

/**
 * ホストの名前から住所（IP）を引く（RemoteImages が、つなぐ前に行き先が外の住所かを確かめるため）。
 * テストでは差し替える（本物の DNS を引かない）
 */
class HostResolver
{
    /**
     * @return list<string> A と AAAA の住所（引けなければ空）
     */
    public function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    if (isset($record[$key]) && is_string($record[$key])) {
                        $ips[] = $record[$key];
                    }
                }
            }
        }

        // DNS で引けない名前（hosts のファイルにだけある名前など）も、システムの引き方で見る（内向きの住所なら断るため）
        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            $ips = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($ips));
    }
}
