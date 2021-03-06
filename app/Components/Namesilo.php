<?php

namespace App\Components;

use Http;
use Log;
use LSS\XML2Array;

class Namesilo
{
    private static $host = 'https://www.namesilo.com/api/';

    // 列出账号下所有域名　Todo Debug测试
    public function listDomains()
    {
        return $this->send('listDomains');
    }

    // 发送请求
    private function send($operation, $data = [])
    {
        $params = [
            'version' => 1,
            'type'    => 'xml',
            'key'     => sysConfig('namesilo_key'),
        ];
        $query = array_merge($params, $data);

        $content = '请求操作：['.$operation.'] --- 请求数据：['.http_build_query($query).']';

        $response = Http::timeout(15)->get(self::$host.$operation.'?'.http_build_query($query));
        if ($response->failed()) {
            Log::error('[Namesilo]请求失败：'.var_export($response, true));
            Helpers::addNotificationLog('[Namesilo API] - ['.$operation.']', $content, 1, sysConfig('webmaster_email'),
                0, var_export($response, true));

            return false;
        }

        $result = XML2Array::createArray($response->json());

        // 出错
        if (empty($result['namesilo']) || $result['namesilo']['reply']['code'] !== 300 || $result['namesilo']['reply']['detail'] !== 'success') {
            Helpers::addNotificationLog('[Namesilo API] - ['.$operation.']', $content, 1,
                sysConfig('webmaster_email'), 0, $result['namesilo']['reply']['detail']);
        } else {
            Helpers::addNotificationLog('[Namesilo API] - ['.$operation.']', $content, 1,
                sysConfig('webmaster_email'), 1, $result['namesilo']['reply']['detail']);
        }

        return $result['namesilo']['reply'];
    }

    // 列出指定域名的所有DNS记录
    public function dnsListRecords($domain)
    {
        return $this->send('dnsListRecords', ['domain' => $domain]);
    }

    // 为指定域名添加DNS记录
    public function dnsAddRecord($domain, $host, $value, $type = 'A', $ttl = 7207)
    {
        return $this->send('dnsAddRecord', ['domain' => $domain, 'rrtype' => $type, 'rrhost' => $host, 'rrvalue' => $value, 'rrttl' => $ttl]);
    }

    // 更新DNS记录
    public function dnsUpdateRecord($domain, $id, $host, $value, $ttl = 7207)
    {
        return $this->send('dnsUpdateRecord', ['domain' => $domain, 'rrid' => $id, 'rrhost' => $host, 'rrvalue' => $value, 'rrttl' => $ttl]);
    }

    // 删除DNS记录
    public function dnsDeleteRecord($domain, $id)
    {
        return $this->send('dnsDeleteRecord', ['domain' => $domain, 'rrid' => $id]);
    }
}
