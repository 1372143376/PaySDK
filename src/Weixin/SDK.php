<?php
namespace Yurun\PaySDK\Weixin;

use \Yurun\PaySDK\Base;
use Yurun\PaySDK\Lib\XML;
use Yurun\PaySDK\Lib\ObjectToArray;
use Yurun\PaySDK\Weixin\Report\Request;
use Yurun\PaySDK\Weixin\Params\PublicParams;

class SDK extends Base
{
	/**
	 * 公共参数
	 * @var \Yurun\PaySDK\Weixin\Params\PublicParams
	 */
	public $publicParams;

	/**
	 * 用于上报的SDK实例
	 * @var \Yurun\PaySDK\Weixin\SDK
	 */
	public $reportSDK;

	/**
	 * 处理执行接口的数据
	 * @param $params
	 * @param &$data 数据数组
	 * @param &$requestData 请求用的数据，格式化后的
	 * @param &$url 请求地址
	 * @return array
	 */
	public function __parseExecuteData($params, &$data, &$requestData, &$url)
	{
		$data = \array_merge(ObjectToArray::parse($this->publicParams), ObjectToArray::parse($params));
		unset($data['apiDomain'], $data['appID'], $data['businessParams'], $data['_apiMethod'], $data['key'], $data['_method'], $data['_isSyncVerify'], $data['certPath'], $data['keyPath'], $data['needSignType'], $data['allowReport'], $data['reportLevel']);
		$data['appid'] = $this->publicParams->appID;
		$data['nonce_str'] = \md5(\uniqid('', true));
		if(!$params->needSignType)
		{
			unset($data['sign_type']);
		}
		foreach($data as $key => $value)
		{
			if(\is_object($value) && \method_exists($value, 'toString'))
			{
				$data[$key] = $value->toString();
			}
		}
		$data['sign'] = $this->sign($data);
		$requestData = $this->parseDataToXML($data);
		if(false === strpos($params->_apiMethod, '://'))
		{
			$url = $this->publicParams->apiDomain . $params->_apiMethod;
		}
		else
		{
			$url = $params->_apiMethod;
		}
	}

	/**
	 * 把数组处理为xml
	 * @param array $data
	 * @return string
	 */
	public function parseDataToXML($data)
	{
		return XML::toString($data);
	}

	/**
	 * 签名
	 * @param $data
	 * @return string
	 */
	public function sign($data)
	{
		$content = $this->parseSignData($data);
		switch($this->publicParams->sign_type)
		{
			case 'HMAC-SHA256':
				return strtoupper(hash_hmac('sha256', $content, $this->publicParams->key));
			case 'MD5':
				return strtoupper(md5($content));
			default:
				throw new \Exception('未知的签名方式：' . $this->publicParams->sign_type);
		}
	}
	
	/**
	 * 验证回调通知是否合法
	 * @param $data
	 * @return bool
	 */
	public function verifyCallback($data)
	{
		if(is_string($data))
		{
			$data = XML::fromString($data);
		}
		if(isset($data['return_code']) && 'SUCCESS' !== $data['return_code'])
		{
			return !isset($data['sign']);
		}
		$content = $this->parseSignData($data);
		switch($this->publicParams->sign_type)
		{
			case 'HMAC-SHA256':
				return strtoupper(hash_hmac('sha256', $content, $this->publicParams->key)) === $data['sign'];
			case 'MD5':
				return strtoupper(md5($content)) === $data['sign'];
			default:
				throw new \Exception('未知的签名方式：' . $this->publicParams->sign_type);
		}
	}

	/**
	 * 验证同步返回内容
	 * @param mixed $params
	 * @param array $data
	 * @return bool
	 */
	public function verifySync($params, $data)
	{
		return $this->verifyCallback($data);
	}

	public function parseSignData($data)
	{
		unset($data['sign']);
		\ksort($data);
		$data['key'] = $this->publicParams->key;
		$content = '';
		foreach ($data as $k => $v){
			if($v != '' && !is_array($v)){
				$content .= $k . '=' . $v . '&';
			}
		}
		return trim($content, '&');
	}
	
	/**
	 * 调用执行接口
	 * @param mixed $params
	 * @param string $method
	 * @return mixed
	 */
	public function execute($params, $format = 'XML')
	{
		if(null !== $this->publicParams->certPath)
		{
			$this->http->sslCert($this->publicParams->certPath);
		}
		if(null !== $this->publicParams->keyPath)
		{
			$this->http->sslKey($this->publicParams->keyPath);
		}
		parent::execute($params, $format);
		if($params->allowReport)
		{
			$this->report($params);
		}
		return $this->result;
	}

	/**
	 * 上报
	 * @param mixed $params
	 * @return void
	 */
	public function report($params)
	{
		switch($this->publicParams->reportLevel)
		{
			case PublicParams::REPORT_LEVEL_NONE:
				return;
			case PublicParams::REPORT_LEVEL_ALL:

				break;
			case PublicParams::REPORT_LEVEL_ERROR:
				if(isset($this->result['return_code']))
				{
					if('SUCCESS' === $this->result['return_code'] && isset($this->result['result_code']) && 'SUCCESS' === $this->result['result_code'])
					{
						return;
					}
				}
				else if(!empty($this->result))
				{
					return;
				}
				break;
		}
		if(null === $this->reportSDK)
		{
			$this->reportSDK = new static($this->publicParams);
		}
		$request = new Request;
		$request->interface_url = $this->url;
		$request->execute_time_ = (int)($this->response->totalTime() * 1000);
		$request->return_code = isset($this->result['return_code']) ? $this->result['return_code'] : (empty($this->result) ? 'FAIL' : 'SUCCESS');
		$request->return_msg = isset($this->result['return_msg']) ? $this->result['return_msg'] : null;
		$request->result_code = isset($this->result['result_code']) ? $this->result['result_code'] : (empty($this->result) ? 'FAIL' : 'SUCCESS');
		$request->err_code = isset($this->result['err_code']) ? $this->result['err_code'] : null;
		$request->err_code_des = isset($this->result['err_code_des']) ? $this->result['err_code_des'] : null;
		$request->user_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
		$request->time = date('YmdHis');
		if(isset($params->device_info))
		{
			$request->device_info = $params->device_info;
		}
		if(isset($params->out_trade_no))
		{
			$request->out_trade_no = $params->out_trade_no;
		}
	}
	
	/**
	 * 检查是否执行成功
	 * @param array $result
	 * @return boolean
	 */
	protected function __checkResult($result)
	{
		return isset($result['return_code']) && 'SUCCESS' === $result['return_code'] && isset($result['result_code']) && 'SUCCESS' === $result['result_code'];
	}
	
	/**
	 * 获取错误信息
	 * @param array $result
	 * @return string
	 */
	protected function __getError($result)
	{
		if(isset($result['result_code']) && 'SUCCESS' !== $result['result_code'])
		{
			return $result['err_code_des'];
		}
		if(isset($result['return_code']) && 'SUCCESS' !== $result['return_code'])
		{
			return $result['return_msg'];
		}
		return '';
	}

	/**
	 * 获取错误代码
	 * @param array $result
	 * @return string
	 */
	protected function __getErrorCode($result)
	{
		if(isset($result['result_code']) && 'SUCCESS' !== $result['result_code'])
		{
			return $result['err_code'];
		}
		if(isset($result['return_code']) && 'SUCCESS' !== $result['return_code'])
		{
			return $result['return_code'];
		}
		return '';
	}
}