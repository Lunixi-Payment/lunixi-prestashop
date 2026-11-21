<form method="post" action="{$lunixi_action|escape:'htmlall':'UTF-8'}">
<input type="hidden" name="lunixi_csrf" value="{$lunixi_csrf|escape:'htmlall':'UTF-8'}">
{if $lunixi_identity_required}
<div class="form-group">
<label class="form-control-label required" for="lunixi-identity-number">{l s='Turkish ID number / Passport number' d='Modules.Lunixipay.Shop'}</label>
<input class="form-control" type="text" id="lunixi-identity-number" name="{$lunixi_identity_field|escape:'htmlall':'UTF-8'}" required maxlength="20" autocomplete="off" spellcheck="false" aria-describedby="lunixi-identity-number-help">
<small class="form-text text-muted" id="lunixi-identity-number-help">{l s='Your 11-digit Turkish ID number, or your passport number. It is saved with your invoice address.' d='Modules.Lunixipay.Shop'}</small>
</div>
{/if}
</form>
