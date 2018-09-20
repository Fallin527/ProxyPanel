<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Models\Goods;
use App\Http\Models\GoodsLabel;
use App\Http\Models\Order;
use App\Http\Models\Payment;
use App\Http\Models\PaymentCallback;
use App\Http\Models\ReferralLog;
use App\Http\Models\User;
use App\Http\Models\UserLabel;
use Illuminate\Http\Request;
use Log;
use DB;

/**
 * 有赞云支付消息推送接收
 * Class YzyController
 *
 * @package App\Http\Controllers
 */
class YzyController extends Controller
{
    // 接收GET请求
    public function index(Request $request)
    {
        \Log::info("【有赞云】回调接口[GET]：" . var_export($request->all(), true) . '[' . getClientIp() . ']');
    }

    // 接收POST请求
    public function store(Request $request)
    {
        \Log::info("【有赞云】回调接口[POST]：" . var_export($request->all(), true));

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (!$data) {
            Log::info('YZY-POST:回调数据无法解析，可能是非法请求[' . getClientIp() . ']');
            exit();
        }

        // 判断消息是否合法
        $msg = $data['msg'];
        $sign_string = $this->systemConfig['youzan_client_id'] . "" . $msg . "" . $this->systemConfig['youzan_client_secret'];
        $sign = md5($sign_string);
        if ($sign != $data['sign']) {
            Log::info('本地签名：' . $sign_string . ' | 远程签名：' . $data['sign']);
            Log::info('YZY-POST:回调数据签名错误，可能是非法请求[' . getClientIp() . ']');
            exit();
        } else {
            // 返回请求成功标识给有赞
            var_dump(["code" => 0, "msg" => "success"]);
        }

        // 容错
        if (!isset($data['kdt_name'])) {
            Log::info("【有赞云】回调数据解析错误，请检查有赞支付设置是否与有赞控制台中的信息保持一致。如果还出现此提示，请执行一遍php artisan cache:clear命令");
            exit();
        }

        // 先写入回调日志
        $this->callbackLog($data['client_id'], $data['id'], $data['kdt_id'], $data['kdt_name'], $data['mode'], $data['msg'], $data['sendCount'], $data['sign'], $data['status'], $data['test'], $data['type'], $data['version']);

        // msg内容经过 urlencode 编码，进行解码
        $msg = json_decode(urldecode($msg), true);

        switch ($data['type']) {
            case 'trade_TradePaid':
                $this->tradePaid($msg);
                break;
            case 'trade_TradeCreate':
                $this->tradeCreate($msg);
                break;
            case 'trade_TradeClose':
                $this->tradeClose($msg);
                break;
            case 'trade_TradeSuccess':
                $this->tradeSuccess($msg);
                break;
            case 'trade_TradePartlySellerShip':
                $this->tradePartlySellerShip($msg);
                break;
            case 'trade_TradeSellerShip':
                $this->tradeSellerShip($msg);
                break;
            case 'trade_TradeBuyerPay':
                $this->tradeBuyerPay($msg);
                break;
            case 'trade_TradeMemoModified':
                $this->tradeMemoModified($msg);
                break;
            default:
                Log::info('【有赞云】回调无法识别，可能是没有启用[交易消息V3]接口，请到有赞云控制台启用消息推送服务');
                exit();
        }

        exit();
    }

    // 交易支付
    private function tradePaid($msg)
    {
        Log::info('【有赞云】回调交易支付');

        $payment = Payment::query()->where('qr_id', $msg['qr_info']['qr_id'])->first();
        if (!$payment) {
            Log::info('【有赞云】回调订单不存在');
            exit();
        }

        if ($payment->status != '0') {
            Log::info('【有赞云】回调订单状态不正确');
            exit();
        }

        // 处理订单
        DB::beginTransaction();
        try {
            // 更新支付单
            $payment->pay_way = $msg['full_order_info']['order_info']['pay_type_str'] == 'WEIXIN_DAIXIAO' ? 1 : 2; // 1-微信、2-支付宝
            $payment->status = 1;
            $payment->save();

            // 更新订单
            $order = Order::query()->with(['user'])->where('oid', $payment->oid)->first();
            $order->status = 2;
            $order->save();

            // 如果买的是套餐，则先将之前购买的所有套餐置都无效，并扣掉之前所有套餐的流量
            $goods = Goods::query()->where('id', $order->goods_id)->first();
            if ($goods->type == 2) {
                $existOrderList = Order::query()
                    ->with(['goods'])
                    ->whereHas('goods', function ($q) {
                        $q->where('type', 2);
                    })
                    ->where('user_id', $order->user_id)
                    ->where('oid', '<>', $order->oid)
                    ->where('is_expire', 0)
                    ->where('status', 2)
                    ->get();

                foreach ($existOrderList as $vo) {
                    Order::query()->where('oid', $vo->oid)->update(['is_expire' => 1]);
                    User::query()->where('id', $order->user_id)->decrement('transfer_enable', $vo->goods->traffic * 1048576);
                }
            }

            // 把商品的流量加到账号上
            User::query()->where('id', $order->user_id)->increment('transfer_enable', $goods->traffic * 1048576);

            // 计算账号过期时间
            if ($order->user->expire_time < date('Y-m-d', strtotime("+" . $goods->days . " days"))) {
                $expireTime = date('Y-m-d', strtotime("+" . $goods->days . " days"));
            } else {
                $expireTime = $order->user->expire_time;
            }

            // 套餐就改流量重置日，流量包不改
            if ($goods->type == 2) {
                if (date('m') == 2 && date('d') == 29) {
                    $traffic_reset_day = 28;
                } else {
                    $traffic_reset_day = date('d') == 31 ? 30 : abs(date('d'));
                }
                User::query()->where('id', $order->user_id)->update(['traffic_reset_day' => $traffic_reset_day, 'expire_time' => $expireTime, 'enable' => 1]);
            } else {
                User::query()->where('id', $order->user_id)->update(['expire_time' => $expireTime, 'enable' => 1]);
            }

            // 写入用户标签
            if ($goods->label) {
                // 用户默认标签
                $defaultLabels = [];
                if ($this->systemConfig['initial_labels_for_user']) {
                    $defaultLabels = explode(',', $this->systemConfig['initial_labels_for_user']);
                }

                // 取出现有的标签
                $userLabels = UserLabel::query()->where('user_id', $order->user_id)->pluck('label_id')->toArray();
                $goodsLabels = GoodsLabel::query()->where('goods_id', $order->goods_id)->pluck('label_id')->toArray();

                // 标签去重
                $newUserLabels = array_values(array_unique(array_merge($userLabels, $goodsLabels, $defaultLabels)));

                // 删除用户所有标签
                UserLabel::query()->where('user_id', $order->user_id)->delete();

                // 生成标签
                foreach ($newUserLabels as $vo) {
                    $obj = new UserLabel();
                    $obj->user_id = $order->user_id;
                    $obj->label_id = $vo;
                    $obj->save();
                }
            }

            // 写入返利日志
            if ($order->user->referral_uid) {
                $referralLog = new ReferralLog();
                $referralLog->user_id = $order->user_id;
                $referralLog->ref_user_id = $order->user->referral_uid;
                $referralLog->order_id = $order->oid;
                $referralLog->amount = $order->amount;
                $referralLog->ref_amount = $order->amount * $this->systemConfig['referral_percent'];
                $referralLog->status = 0;
                $referralLog->save();
            }

            // 取消重复返利
            User::query()->where('id', $order->user_id)->update(['referral_uid' => 0]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            Log::info('【有赞云】回调更新支付单和订单异常：' . $e->getMessage());
        }

        exit();
    }

    // 创建交易
    private function tradeCreate($msg)
    {
        Log::info('【有赞云】回调创建交易');
        exit();
    }

    // 关闭交易（无视，系统自带15分钟自动关闭未支付订单的定时任务）
    private function tradeClose($msg)
    {
        Log::info('【有赞云】回调关闭交易');

        exit();
    }

    // 交易成功
    private function tradeSuccess($msg)
    {
        Log::info('【有赞云】回调交易成功');

        exit();
    }

    // 卖家部分发货
    private function tradePartlySellerShip($msg)
    {
        Log::info('【有赞云】回调卖家部分发货');
        exit();
    }

    // 卖家发货
    private function tradeSellerShip($msg)
    {
        Log::info('【有赞云】回调卖家发货');
        exit();
    }

    // 买家付款
    private function tradeBuyerPay($msg)
    {
        Log::info('【有赞云】回调买家付款');
        exit();
    }

    // 卖家修改交易备注
    private function tradeMemoModified($msg)
    {
        Log::info('【有赞云】回调卖家修改交易备注');
        exit();
    }

    public function show(Request $request)
    {
        exit('show');
    }

    // 写入回调请求日志
    private function callbackLog($client_id, $yz_id, $kdt_id, $kdt_name, $mode, $msg, $sendCount, $sign, $status, $test, $type, $version)
    {
        $paymentCallback = new PaymentCallback();
        $paymentCallback->client_id = $client_id;
        $paymentCallback->yz_id = $yz_id;
        $paymentCallback->kdt_id = $kdt_id;
        $paymentCallback->kdt_name = $kdt_name;
        $paymentCallback->mode = $mode;
        $paymentCallback->msg = urldecode($msg);
        $paymentCallback->sendCount = $sendCount;
        $paymentCallback->sign = $sign;
        $paymentCallback->status = $status;
        $paymentCallback->test = $test;
        $paymentCallback->type = $type;
        $paymentCallback->version = $version;
        $paymentCallback->save();

        return $paymentCallback->id;
    }
}
