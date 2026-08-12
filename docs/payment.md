# Namingo Registry: Registrar Payment Guide

Namingo Registry provides an efficient and automated deposit system that allows registrars to add funds to their accounts quickly. This ensures uninterrupted access to domain operations without manual delays. Below are the payment setup instructions for both registry providers and registrars.

---

## If You Are a Registry Provider Using Namingo

To enable the payment system, you must configure your accounts with the supported payment providers and provide the required credentials in the `.env` file located at `/var/www/cp/`. After configuration, clear the application cache to apply the changes.

### Supported Payment Providers

- **[Adyen](https://www.adyen.com)**
- **[LiqPay](https://www.liqpay.ua/en)**
- **[Nicky.me](https://nicky.me)**
- **[NOWPayments](https://nowpayments.io)**
- **[plata by mono](https://monobank.ua/en/plata-by-mono)**
- **[Revolut](https://www.revolut.com)**
- **[Revolut Pay](https://www.revolut.com/revolut-pay/)**
- **[Stripe](https://stripe.com)**

### Required Configuration Variables

Modify the default values in the `.env` file with your specific credentials as shown below:

```env
ENABLED_GATEWAYS=adyen,liqpay,nicky,now,plata,revolut,revolutpay,stripe

STRIPE_SECRET_KEY='stripe-secret-key'
STRIPE_PUBLISHABLE_KEY='stripe-publishable-key'

REVOLUT_SANDBOX=true
REVOLUT_SECRET_KEY=sk_your_sandbox_secret_key
REVOLUT_PUBLIC_KEY=pk_your_sandbox_public_key
REVOLUT_API_VERSION=2024-09-01

REVOLUT_CARD_WEBHOOK_SECRET=
REVOLUT_PAY_WEBHOOK_SECRET=

LIQPAY_PUBLIC_KEY='liqpay-public-key'
LIQPAY_PRIVATE_KEY='liqpay-private-key'

PLATA_TOKEN='plata-token'

ADYEN_API_KEY='adyen-api-key'
ADYEN_MERCHANT_ID='adyen-merchant-id'
ADYEN_THEME_ID='adyen-theme-id'
ADYEN_BASE_URI='https://checkout-test.adyen.com/v70/'
ADYEN_BASIC_AUTH_USER='adyen-basic-auth-user'
ADYEN_BASIC_AUTH_PASS='adyen-basic-auth-pass'
ADYEN_HMAC_KEY='adyen-hmac-key'

NOW_API_KEY='now-api-key'

NICKY_API_KEY='nicky-api-key'
```

Use keys from the same environment. Set `REVOLUT_SANDBOX=false` and replace both API keys with production keys when going live. `REVOLUT_PUBLIC_KEY` is required only when `revolutpay` is enabled.

After saving the file, clear the cache using the **Clear Cache** button located in the **Server Health** page of the Namingo Control Panel.

#### Revolut and Revolut Pay Additional Configuration

Revolut's Business sandbox UI may not expose webhook registration. The bundled CLI uses the Merchant API directly:

```bash
php bin/revolut_webhooks.php webhook:list
php bin/revolut_webhooks.php webhook:register
```

The register command defaults to both gateways. It creates or reuses:

- `APP_URL/payment/revolut/webhook`
- `APP_URL/payment/revolutpay/webhook`

It retrieves each webhook's independent `signing_secret` and writes it to the correct `.env` variable. It does not print either secret. The signing secret is not the public API key and is not the webhook ID.

Other commands:

```bash
php bin/revolut_webhooks.php webhook:register revolut
php bin/revolut_webhooks.php webhook:register revolutpay
php bin/revolut_webhooks.php webhook:delete WEBHOOK_UUID
```

Clear the cache using the **Clear Cache** button located in the **Server Health** page of the Namingo Control Panel.

---

## If You Are a Registrar of a Registry Using Namingo

Registrars can easily deposit funds into their accounts to use for domain operations. The system allows for immediate balance updates, ensuring seamless functionality.

### How to Deposit Funds

1. **Access the Financials Menu**  
   Log in to the Namingo Control Panel and navigate to the **Financials** menu.

2. **Add a Deposit**  
   Click on **Add Deposit**. Enter the amount you wish to deposit; the amount will be displayed in your account currency.

3. **Choose a Payment Provider**  
   Select one of the following payment providers:
	- **[Adyen](https://www.adyen.com)**
	- **[LiqPay](https://www.liqpay.ua/en)**
	- **[Nicky.me](https://nicky.me)**
	- **[NOWPayments](https://nowpayments.io)**
	- **[plata by mono](https://monobank.ua/en/plata-by-mono)**
	- **[Revolut](https://www.revolut.com)**
	- **[Revolut Pay](https://www.revolut.com/revolut-pay/)**
	- **[Stripe](https://stripe.com)**

4. **Complete the Payment**  
   - For **Nicky.me** and **NOWPayments**:
     - After checkout, you will be redirected to a payment status page.
     - Save the link to monitor the payment status.
     - Reload the page after the payment is confirmed to update your balance.
   - For other methods: You will be redirected to the secure payment page to complete the transaction.

5. **Verify the Deposit**  
   Once the payment is completed and verified, the deposited amount will reflect in your account balance.

---

For additional support or questions about the process, please contact Namingo Registry Support. We are here to assist you at every step.