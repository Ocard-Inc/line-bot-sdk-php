<?php

/**
 * Copyright 2016 LINE Corporation
 *
 * LINE Corporation licenses this file to you under the Apache License,
 * version 2.0 (the "License"); you may not use this file except in compliance
 * with the License. You may obtain a copy of the License at:
 *
 *   https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

namespace LINE\LINEBot\HTTPClient;

use LINE\LINEBot\Constant\Meta;
use LINE\LINEBot\HTTPClient;
use LINE\LINEBot\Response;

/**
 * Class CurlHTTPClient.
 *
 * A HTTPClient that uses cURL.
 *
 * @package LINE\LINEBot\HTTPClient
 */
class CurlHTTPClient implements HTTPClient
{
    /** @var array */
    private $authHeaders;
    /** @var array */
    private $userAgentHeader = ['User-Agent: LINE-BotSDK-PHP/' . Meta::VERSION];

    /**
     * CurlHTTPClient constructor.
     *
     * @param string $channelToken Access token of your channel.
     */
    public function __construct($channelToken, $module_id = null)
    {
        $this->authHeaders = [
            "Authorization: Bearer $channelToken",
        ];
        if($module_id)
        {
            $this->authHeaders[] = 'X-Line-Bot-Id: '.$module_id;
        }
        // OUTPUT($this->authHeaders);
        // echo $module_id.'|';
    }

    /**
     * Sends GET request to LINE Messaging API.
     *
     * @param string $url Request URL.
     * @return Response Response of API request.
     */
    public function get($url)
    {
        return $this->sendRequest('GET', $url, [], []);
    }

    /**
     * Sends POST request to LINE Messaging API.
     *
     * @param string $url Request URL.
     * @param array $data Request body.
     * @return Response Response of API request.
     */
    public function post($url, array $data, $add_header = [])
    {
        $header = ['Content-Type: application/json; charset=utf-8'];
        if(!empty($add_header))
        {
            $header = array_merge($header, $add_header);
        }
        return $this->sendRequest('POST', $url, $header, $data);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $additionalHeader
     * @param array $reqBody
     * @return Response Response 物件；若重試後仍為連線層錯誤，回傳 httpStatus=0 的失敗 Response（不中斷呼叫端流程）。
     */
    private function sendRequest($method, $url, array $additionalHeader, array $reqBody)
    {
        $headers = array_merge($this->authHeaders, $this->userAgentHeader, $additionalHeader);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_HEADER => true,
        ];

        if ($method === 'POST') {
            if (empty($reqBody)) {
                // Rel: https://github.com/line/line-bot-sdk-php/issues/35
                $options[CURLOPT_HTTPHEADER][] = 'Content-Length: 0';
            } else {
                $options[CURLOPT_POSTFIELDS] = json_encode($reqBody);
            }
        }

        $curl = new Curl($url);
        $curl->setoptArray($options);
        $result = $curl->exec();

        if ($curl->errno()) {
            $logBody = isset($options[CURLOPT_POSTFIELDS]) ? $options[CURLOPT_POSTFIELDS] : '';

            // HTTP/2 framing error (errno 16) 多為連線重用造成的暫時性錯誤，重試一次並強制建立新連線；其他 errno 不重試
            if ($curl->errno() === 16) {
                log_message('error', '[CurlHTTPClient] curl error, retrying with fresh connection | url=' . $url . ' | body=' . $logBody . ' | errno=' . $curl->errno() . ' | error=' . $curl->error());

                $curl = new Curl($url);
                $options[CURLOPT_FRESH_CONNECT] = true;
                $curl->setoptArray($options);
                $result = $curl->exec();

                if ($curl->errno()) {
                    log_message('error', '[CurlHTTPClient] curl error after retry | url=' . $url . ' | body=' . $logBody . ' | errno=' . $curl->errno() . ' | error=' . $curl->error());
                    // 重試後仍失敗：不中斷呼叫端流程，回傳失敗 Response 讓 isSucceeded() 判定為 false
                    return new Response(0, '', []);
                }
            } else {
                log_message('error', '[CurlHTTPClient] curl error | url=' . $url . ' | body=' . $logBody . ' | errno=' . $curl->errno() . ' | error=' . $curl->error());
                // 不重試：不中斷呼叫端流程，回傳失敗 Response 讓 isSucceeded() 判定為 false
                return new Response(0, '', []);
            }
        }

        $info = $curl->getinfo();
        $httpStatus = $info['http_code'];

        $responseHeaderSize = $info['header_size'];

        $responseHeaderStr = substr($result, 0, $responseHeaderSize);
        $responseHeaders = [];
        foreach (explode("\r\n", $responseHeaderStr) as $responseHeader) {
            $kv = explode(':', $responseHeader, 2);
            if (count($kv) === 2) {
                $responseHeaders[$kv[0]] = trim($kv[1]);
            }
        }

        $body = substr($result, $responseHeaderSize);

        return new Response($httpStatus, $body, $responseHeaders);
    }
}
