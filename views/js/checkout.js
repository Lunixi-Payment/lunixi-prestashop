/* No merchant secrets or card inputs are handled by this adapter. */
(function () {
  'use strict';
  function mount() {
    var target = document.getElementById('lunixi-checkout-form');
    if (!target || target.dataset.lunixiMounted || !window.Lunixi || !window.Lunixi.checkout) return;
    var config = JSON.parse(target.dataset.lunixiConfig);
    target.dataset.lunixiMounted = '1';
    window.Lunixi.checkout.create(config).mount('#lunixi-checkout-form');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
}());
