<?php

namespace Plugin\EpayLDC;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    /**
     * 插件启动
     */
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['EpayLDC'] = [
                    'name' => $this->getConfig('display_name', 'LINUX DO Credit'),
                    'icon' => $this->getConfig('icon', '💎'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    /**
     * 插件配置表单
     */
    public function form(): array
    {
        return [
            'url' => [
                'label' => '支付网关地址',
                'type' => 'string',
                'required' => true,
                'description' => '默认为 https://credit.linux.do/epay'
            ],
            'pid' => [
                'label' => '商户ID (Client ID)',
                'type' => 'string',
                'required' => true,
                'description' => '在 LINUX DO Credit 后台创建应用后获取的 pid'
            ],
            'key' => [
                'label' => '通信密钥 (Client Secret)',
                'type' => 'string',
                'required' => true,
                'description' => '在 LINUX DO Credit 后台创建应用后获取的 key'
            ],
            'type' => [
                'label' => '支付类型',
                'type' => 'string',
                'description' => '固定填写 epay'
            ],
            'display_name' => [
                'label' => '显示名称',
                'type' => 'string',
                'description' => '在前端显示的支付方式名称，默认：LINUX DO Credit'
            ],
            'icon' => [
                'label' => '图标',
                'type' => 'string',
                'description' => '支付方式图标，默认：💎'
            ],
        ];
    }

    /**
     * 发起支付
     */
    public function pay($order): array
    {
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->getConfig('pid')
        ];

        if ($paymentType = $this->getConfig('type')) {
            $params['type'] = $paymentType;
        }

        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('key');
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';

        return [
            'type' => 1,
            'data' => rtrim($this->getConfig('url'), '/') . '/pay/submit.php?' . http_build_query($params)
        ];
    }

    /**
     * 异步通知验签（保留兼容，但实际依赖主动查询）
     */
    public function notify($params): array|bool
    {
        if (!isset($params['sign'])) {
            return false;
        }

        $sign = $params['sign'];
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->getConfig('key');

        if ($sign !== md5($str)) {
            return false;
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }

    /**
     * 主动查询订单状态
     */
    public function query(string $tradeNo): array|bool
    {
        $baseUrl = $this->getConfig('url');
        if (empty($baseUrl)) {
            Log::error('EpayLDC query error: url config is empty');
            return false;
        }

        $url = rtrim($baseUrl, '/') . '/api.php';

        $params = [
            'act' => 'order',
            'pid' => $this->getConfig('pid'),
            'key' => $this->getConfig('key'),
            'out_trade_no' => $tradeNo,
        ];

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 10,
                'verify' => false
            ]);
            $response = $client->get($url, ['query' => $params]);
            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['code']) && $result['code'] == 1
                && isset($result['status']) && $result['status'] == 1) {
                return [
                    'trade_no' => $result['out_trade_no'],
                    'callback_no' => $result['trade_no'],
                ];
            }
        } catch (\Exception $e) {
            Log:: error('EpayLDC query error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * 插件定时任务：每分钟检查待支付订单
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            $this->checkPendingOrders();
        })->everyMinute()->name('epayldc: check-pending-orders')->withoutOverlapping();
    }

    /**
     * 检查所有待支付的 EpayLDC 订单
     */
    protected function checkPendingOrders(): void
    {
        $payments = Payment::where('payment', 'EpayLDC')
            ->where('enable', 1)
            ->pluck('id')
            ->toArray();

        if (empty($payments)) {
            return;
        }

        $orders = Order:: where('status', Order::STATUS_PENDING)
            ->whereIn('payment_id', $payments)
            ->where('created_at', '>=', time() - 86400)
            ->get();

        foreach ($orders as $order) {
            try {
                $queryResult = $this->query($order->trade_no);

                if ($queryResult && isset($queryResult['trade_no'], $queryResult['callback_no'])) {
                    $orderService = new OrderService($order);
                    $orderService->paid($queryResult['callback_no']);
                    Log::info("EpayLDC order {$order->trade_no} confirmed paid via query.");
                }
            } catch (\Exception $e) {
                Log::error("EpayLDC check order {$order->trade_no} failed: " . $e->getMessage());
            }
        }
    }
}
