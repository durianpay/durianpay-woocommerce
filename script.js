(function() {
    var data = durianpay_wc_checkout_vars;

    data.onSuccess = function(response) {
      console.log("payment success: ", response)
      document.getElementById('durianpay_payment_id').value = response.payment_id;
      document.durianpayform.submit();
    }

    data.onFailure = function(response) {
      console.log("payment failed: ", response)
    }
    
    var durianpayCheckout = Durianpay.init(data);

    // global method
    function openCheckout() {
        durianpayCheckout.checkout();
    }

    function addEvent(element, evnt, funct) {
    if (element.attachEvent) {
        return element.attachEvent('on' + evnt, funct);
    } else return element.addEventListener(evnt, funct, false);
    }

    if (document.readyState === 'complete') {
        addEvent(document.getElementById('dpay-checkout-btn'), 'click', openCheckout);
        openCheckout();
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            addEvent(document.getElementById('dpay-checkout-btn'), 'click', openCheckout);
            openCheckout();
        });
    }
  })();