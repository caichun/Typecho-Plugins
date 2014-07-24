<?php
/**
 * 同步发表
 * 
 * @package TypechoSync
 * @author 息E-敛
 * @version 0.4.0
 * @link http://tennsinn.com
 **/
 
 class TypechoSync_Plugin implements Typecho_Plugin_Interface
 {
	/* 激活插件方法 */
	public static function activate()
	{
		Typecho_Plugin::factory('Words_Action')->finishWord = array('TypechoSync_Plugin', 'syncWords');
		Typecho_Plugin::factory('Words_Action')->writeOption = array('TypechoSync_Plugin', 'optionWords');
		Typecho_Plugin::factory('Widget_Abstract_Contents')->filter = array('TypechoSync_Plugin', 'syncPost');
		Typecho_Plugin::factory('admin/write-post.php')->option = array('TypechoSync_Plugin', 'optionWrite');
		return _t('插件已成功开启，请务必在设置中填写相关内容');
	}
 
	/* 禁用插件方法 */
	public static function deactivate()
	{
	}
 
	/* 插件配置方法 */
	public static function config(Typecho_Widget_Helper_Form $form)
	{
		$sync = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'sync', 
			array(
				'post' => '同步文章',
				'words' => '同步心情碎语'
			), 
			NULL, 
			'同步内容', 
			_t('请选择需要同步的项目')
		);
		$form->addInput($sync->multiMode());

		$client = new Typecho_Widget_Helper_Form_Element_Checkbox(
			'client',
			array(
				'sina' => '同步到新浪微博（暂未可用）',
				'tencent' => '同步到腾讯微博'
			),
			NULL,
			'同步平台',
			_t('请选择需要同步到的社交平台  <a href="http://blog.tennsinn.com/TypechoSync">点此进行授权</a>')
		);
		$form->addInput($client->multiMode());

		$sinaToken = new Typecho_Widget_Helper_Form_Element_Text('sinaToken', NULL, NULL, _t('新浪微博access_token'), _t('请填入获取到的新浪微博access_token值'));
		$form->addInput($sinaToken);

		$tencentOpenid = new Typecho_Widget_Helper_Form_Element_Text('tencentOpenid', NULL, NULL, _t('腾讯微博Openid'), _t('请填入获取到的腾讯微博Openid值'));
		$form->addInput($tencentOpenid);

		$tencentToken = new Typecho_Widget_Helper_Form_Element_Text('tencentToken', NULL, NULL, _t('腾讯微博access_token'), _t('请填入获取到的腾讯微博access_token值'));
		$form->addInput($tencentToken);

		$template = new Typecho_Widget_Helper_Form_Element_Text('template', NULL, '{site}：发表了一篇博文《{title}》：{text}', _t('发表模板'), _t('同步时使用的模板<br>{site}：站点名称；{title}：文章标题；{text}：文章摘要<br>摘要默认长度80，请注意总长勿超过微博允许的字数'));
		$form->addInput($template);
	}
 
	/* 个人用户的配置方法 */
	public static function personalConfig(Typecho_Widget_Helper_Form $form)
	{
	}

	/**
	 * 文章写作页面同步开关
	 * 
	 * @param class $class 类Widget_Contents_Post_Edit
	 * @return void
	 */
	public static function optionWrite($class)
	{
		$settings = Helper::options()->plugin('TypechoSync');
		if(isset($settings->sync) && in_array('post', $settings->sync))
		{
			$checked = (!$class->have() || ($class->type == 'post_draft' && $class->parent == 0)) ? true : false;
			self::optionSyncs($checked);
		}
	}

	/**
	 * 碎语新增页面同步开关
	 * 
	 * @param  class $class 类Words_Action
	 * @return void
	 */
	public static function optionWords($class)
	{
		$settings = Helper::options()->plugin('TypechoSync');
		if(isset($settings->sync) && in_array('words', $settings->sync))
		{
			$checked = $class->request->type == 'new' ? true : false;
			self::optionSyncs($checked);
	?>
			<style>
				.category-option ul
				{
					margin-left: 63px;
					margin-right: 100px;
				}
				.category-option li
				{
					display: inline-block;
					margin-left: 10px;
				}
				.typecho-label
				{
					line-height: 40px;
					font-weight: bold;
					float: left;
				}
			</style>
	<?php
		}
	}

	/**
	 * 页面同步开关
	 * 
	 * @param  bool $checked 当前选中与否
	 * @return void
	 */
	public static function optionSyncs($checked)
	{
		$settings = Helper::options()->plugin('TypechoSync');
		$clients = array();
		if(isset($settings->client))
		{
			if(!empty($settings->sinaToken) && in_array('sina', $settings->client))
				$clients['sina'] = '新浪微博';
			if(!empty($settings->tencentToken) && !empty($settings->tencentOpenid) && in_array('tencent', $settings->client))
				$clients['tencent'] = '腾讯微博';
		}
	?>
		<section class="typecho-post-option category-option">
			<label class="typecho-label"><?php _e('同步选项'); ?></label>
			<ul>
				<?php if(!$clients): ?>
					<li><label>请进入设置填写相应的信息</label></li>
				<?php else: ?>
					<?php foreach($clients as $clientSlug => $clientText): ?>
						<li><input id="sync-<?=$clientSlug?>" type="checkbox" value="<?=$clientSlug?>" name="syncs[]"<?php if($checked): ?> checked="true"<?php endif; ?>/><label for="sync-<?=$clientSlug?>"><?=$clientText?></label></li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>
		</section>
	<?php
	}

	/**
	 * 同步发表文章
	 * 
	 * @param  array $contents 文章内容
	 * @param  class $class 类Widget_Contents_Post_Edit
	 * @return array
	 */
	public static function syncPost($contents, $class)
	{
		if(!is_a($class, 'Widget_Contents_Post_Edit'))
			return $contents;
		$settings = Helper::options()->plugin('TypechoSync');
		if(isset($settings->sync) && in_array('post', $settings->sync)
			&& isset($class->request->syncs) && !empty($class->request->syncs)
			&& $class->request->is('do=publish')
			&& ($contents['status'] == 'publish' && !$contents['password'])
		)
		{
			$options = Helper::options();
			// 处理文字
			$text = $contents['text'];
			$text = $contents['isMarkdown'] ? MarkdownExtraExtended::defaultTransform($text) : Typecho_Common::cutParagraph($text);
			$text = Typecho_Common::fixHtml($text);
			// 获取第一张图片
			if(preg_match("/\<img.*?src\=\"(.*?)\"[^>]*>/i", $text, $pic))
				$pic = $pic[1];
			else
				$pic = NULL;
			$text = explode('<!--more-->', $text);
			$text = Typecho_Common::subStr(strip_tags($text[0]), 0, 80, '...');
			// 模板文本
			$string = $settings->template ? $settings->template : '{site}：发表了一篇博文《{title}》：{text}';
			$search = array('{site}', '{title}', '{text}');
			$replace = array($options->title, $contents['title'], $text);
			$string = str_replace($search, $replace, $string);
			// 逐个同步
			$syncs = array_unique(array_map('trim', $class->request->syncs));
			if(in_array('sina', $syncs) && !empty($settings->sinaToken))
				self::syncSina($string, $contents['permalink'], $pic);
			if(in_array('tencent', $syncs) && !empty($settings->tencentToken) && !empty($settings->tencentOpenid))
				self::syncTencent($string, $contents['permalink'], $pic);
		}
		return $contents;
	}
 
	/**
	 * 同步心情碎语
	 * 
	 * @param  string $newWord 碎语内容
	 * @return void
	 */
	public static function syncWords($newWord, $class)
	{
		$settings = Helper::options()->plugin('TypechoSync');
		if(isset($settings->sync) && in_array('words', $settings->sync)
			&& isset($class->request->syncs) && !empty($class->request->syncs))
		{
			$options = Helper::options();
			$syncs = array_unique(array_map('trim', $class->request->syncs));
			if(in_array('sina', $syncs) && !empty($settings->sinaToken))
				self::syncSina($newWord['content']);
			if(in_array('tencent', $syncs) && !empty($settings->tencentToken) && !empty($settings->tencentOpenid))
				self::syncTencent($newWord['content']);
		}
	}

	/**
	 * 同步到新浪微博
	 * 
	 * @param  string $string 文字内容
	 * @param  string $permalink 网页链接
	 * @param  string $pic 图片链接
	 * @return void
	 */
	public static function syncSina($string, $permalink=NULL, $pic=NULL)
	{
		$options = Helper::options();
		$settings = $options->plugin('TypechoSync');
		$status = $string;
		// 添加链接
		if($permalink)
			$status .= $permalink;
		require_once('libs/classSina.php');
		$clientSina = new SaeTClientV2(NULL, NULL, $settings->sinaToken);
		// 同步
		if($pic)
			$response = $clientSina->upload($status, $pic);
		else
			$response = $clientSina->update($status);
		// 记录错误信息
		if(isset($response['error_code']))
		{
			$fileLog = @fopen(dirname(__FILE__).'/errorlog.txt', 'a');
			fwrite($fileLog, date('Y-m-d H:i', time(0)+$options->timezone).': sina: '.$response['error']."\r\n");
			fclose($fileLog);
		}
	}

	/**
	 * 同步到腾讯微博
	 * 
	 * @param  string $string 文字内容
	 * @param  string $permalink 网页链接
	 * @param  string $pic 图片链接
	 * @return void
	 */
	public static function syncTencent($string, $permalink=NULL, $pic=NULL)
	{
		$options = Helper::options();
		$settings = $options->plugin('TypechoSync');
		require_once('libs/classTencent.php');
		$params['oauth_consumer_key'] = '801526744';
		$params['access_token'] = $settings->tencentToken;
		$params['openid'] = $settings->tencentOpenid;
		$params['clientip'] = Common::getClientIp();
		$params['oauth_version'] = '2.a';
		$params['scope'] = 'all';
		$params['format'] = 'json';
		$content = $string;
		// 添加链接
		if($permalink)
			$content .= $permalink;
		// 添加图片
		if($pic)
		{
			$params['pic_url'] = $pic;
			$apiUrl = 't/add_pic_url';
		}
		else
			$apiUrl = 't/add';
		$params['content'] = $content;
		// 同步
		$response = Tencent::api($apiUrl, $params, 'POST');
		$response = json_decode($response, true);
		// 记录错误信息
		if($response['ret'])
		{
			$fileLog = @fopen(dirname(__FILE__).'/errorlog.txt', 'a');
			fwrite($fileLog, date('Y-m-d H:i', time(0)+$options->timezone).': tencent: '.$response['msg']."\r\n");
			fclose($fileLog);
		}
	}
}
?>