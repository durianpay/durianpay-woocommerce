const settings = window.wc.wcSettings.getSetting('durianpay_data', {});
const label = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('Durianpay for woocommerce', 'durianpay');
const Content = () => {
    return window.wp.htmlEntities.decodeEntities(settings.description || '');
};
const Block_Gateway = {
    name: 'durianpay',
    label: label,
    content: Object(window.wp.element.createElement)(Content, null ),
    edit: Object(window.wp.element.createElement)(Content, null ),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );
 