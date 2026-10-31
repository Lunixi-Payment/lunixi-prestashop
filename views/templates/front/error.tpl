{extends file='page.tpl'}
{block name='page_content'}<p>{l s='Payment is pending. Please retry this checkout or contact the shop.' d='Modules.Lunixipay.Shop'}</p>{if isset($lunixi_retry_url)}
<form method="post" action="{$lunixi_retry_url|escape:'htmlall':'UTF-8'}">
<input type="hidden" name="lunixi_csrf" value="{$lunixi_retry_csrf|escape:'htmlall':'UTF-8'}">
<button type="submit" class="btn btn-primary">{l s='Retry this payment attempt' d='Modules.Lunixipay.Shop'}</button>
</form>
{/if}
{/block}
