<div class="card"><div class="card-header">Lunixi</div><div class="card-body">
{if $lunixi_record}
<p>{l s='Gateway state' d='Modules.Lunixipay.Admin'}: {$lunixi_record.state|escape:'htmlall':'UTF-8'}</p>
<p>{l s='Gross charged / refunded (minor units)' d='Modules.Lunixipay.Admin'}: {$lunixi_record.chargedAmount|default:0|escape:'htmlall':'UTF-8'} / {$lunixi_record.refundedAmount|default:0|escape:'htmlall':'UTF-8'}</p>
{/if}
<p>{l s='Gateway refunds move funds only. Create native item credit notes and stock returns separately according to your tax policy; do not repeat the gateway refund.' d='Modules.Lunixipay.Admin'}</p>
<p>{l s='After an uncertain result, keep the same operation ID and exact amount. Reconcile before a different operation.' d='Modules.Lunixipay.Admin'}</p>
<form method="post" action="{$lunixi_admin_url|escape:'htmlall':'UTF-8'}">
<input type="hidden" name="token" value="{$lunixi_token|escape:'htmlall':'UTF-8'}">
<input type="hidden" name="id_order" value="{$lunixi_order_id|intval}">
<label>{l s='Operation ID' d='Modules.Lunixipay.Admin'} <input name="operation_id" value="{$lunixi_operation|escape:'htmlall':'UTF-8'}" size="48" {if $lunixi_pending}readonly{/if} required pattern="[a-f0-9]{48}"></label>
<label>{l s='Amount in order currency' d='Modules.Lunixipay.Admin'} <input name="amount" value="{$lunixi_amount|escape:'htmlall':'UTF-8'}" {if $lunixi_pending}readonly{/if} type="text" inputmode="decimal" pattern="[0-9]+(\.[0-9]+)?" autocomplete="off"></label>
<button class="btn btn-primary" name="lunixi_action" value="refund" {if $lunixi_pending && $lunixi_pending.action != 'refund'}disabled{/if}>{l s='Refund' d='Modules.Lunixipay.Admin'}</button>
<button class="btn btn-secondary" name="lunixi_action" value="capture" {if $lunixi_pending && $lunixi_pending.action != 'capture'}disabled{/if}>{l s='Capture' d='Modules.Lunixipay.Admin'}</button>
<button class="btn btn-secondary" name="lunixi_action" value="void" {if $lunixi_pending && $lunixi_pending.action != 'void'}disabled{/if}>{l s='Void authorization' d='Modules.Lunixipay.Admin'}</button>
<button class="btn btn-secondary" name="lunixi_action" value="reconcile">{l s='Reconcile' d='Modules.Lunixipay.Admin'}</button>
</form></div></div>
