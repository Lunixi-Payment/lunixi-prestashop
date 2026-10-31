{extends file='page.tpl'}
{block name='page_title'}{l s='Secure payment' d='Modules.Lunixipay.Shop'}{/block}
{block name='page_content'}
<div id="lunixi-checkout-form" data-lunixi-config="{$lunixi_browser_config|escape:'htmlall':'UTF-8'}"></div>
<noscript>{l s='Enable JavaScript to load the secure payment form.' d='Modules.Lunixipay.Shop'}</noscript>
{/block}
