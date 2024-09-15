<script>
    document.addEventListener('DOMContentLoaded', function() {
        var nodapayOptions = document.querySelectorAll('input[name="payment-option"][data-module-name="nodapay"]');
        var elseOptions = document.querySelectorAll('input[name="payment-option"]:not([data-module-name="nodapay"])');
        const placeButton = document.querySelector('#checkout-payment-step #payment-confirmation button');

        nodapayOptions.forEach(function(nodapayOption) {
            nodapayOption.addEventListener('change', function() {
                if (this.checked) {
                    placeButton.classList.add('nodapay-button');
                    placeButton.style.backgroundImage = 'url({$nodaLogo})';
                    console.log('NodaPay choice');
                }
            });

            if (nodapayOption.checked) {
              placeButton.classList.add('nodapay-button');
              placeButton.style.backgroundImage = 'url({$nodaLogo})';
              console.log('NodaPay choice');
            }
        });

        elseOptions.forEach(function(option) {
            option.addEventListener('change', function() {
                const placeButton = document.querySelector('#checkout-payment-step #payment-confirmation button');
                if (this.checked) {
                    console.log('NodaPay does not choice');
                    placeButton.style.backgroundImage = '';
                    placeButton.classList.remove('nodapay-button');
                }
            });
        });
    });
</script>
<style>
    body button.nodapay-button {
        width: 100%;
        height: 100%;
        padding: 10px 20px;
        background-color: transparent;
        border: black 2px solid;
        border-radius: 7px;
        overflow-y: hidden;
        overflow-x: hidden;
        transition-duration: .3s;
        background-repeat: no-repeat !important;
        background-position: center !important;
        color: transparent !important;
        --tw-translate-x: 0;
        --tw-translate-y: 0;
        --tw-rotate: 0;
        --tw-skew-x: 0;
        --tw-skew-y: 0;
        --tw-scale-x: 1;
        --tw-scale-y: 1;
        --shadow-md: -8px 8px 0 0 black;
        transform: translateX(var(--tw-translate-x)) translateY(var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y));
        --tw-shadow: 0 0 #0000;
        --tw-ring-offset-width: 0px;
        --tw-ring-offset-color: #fff;
        --tw-ring-color: rgba(147,197,253,0.5);
        --tw-ring-offset-shadow: 0 0 #0000;
        --tw-ring-shadow: 0 0 #0000;
    }

    body button.nodapay-button.disabled {
        color: #ddd;
        opacity: .65;
    }

    body button.nodapay-button:hover {
        --tw-translate-x: 8px;
        --tw-translate-y: -8px;
        --tw-shadow: var(--shadow-md);
        box-shadow: var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow);
        background-color: unset;
        color: transparent !important;
    }

    body button.nodapay-button.loading {
        opacity: 0.7;
    }

    body .nodapay-loader {
        display: none;
        border: 9px solid #f3f3f3;
        border-top: 9px solid #3498db;
        border-radius: 50%;
        width: 50px;
        height: 50px;
        animation: spin 2s linear infinite;
        position: absolute;
        left: 45%;
        top: 10%;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>
