// dom controllers
document.addEventListener('DOMContentLoaded', () => {
    const promoHandler = document.getElementById('promo');
    const promoHolder = document.getElementById('promo-holder');
    const promoCloser = document.getElementById('promo-closer');

    if (promoHandler && promoHolder) {
        promoHandler.addEventListener('click', () => {
            promoHolder.classList.toggle('active');
            promoHandler.classList.toggle('inactive');
        });
    }

    if (promoCloser && promoHolder && promoHandler) {
        promoCloser.addEventListener('click', () => {
            promoHolder.classList.toggle('active');
            promoHandler.classList.toggle('inactive');
        });
    }
});