# Payment Methods for Registrars

Namingo Registry provides an efficient and automated deposit system that allows registrars to add funds to their accounts quickly. This ensures uninterrupted access to domain operations without manual delays. Below are the payment setup instructions for both registry providers and registrars.

---

## If You Are a Registry Provider Using Namingo

To enable the payment system, you must configure your accounts with the supported payment providers and provide the required credentials in the `.env` file located at `/var/www/cp/`. After configuration, clear the application cache to apply the changes.

### Supported Payment Providers

- **[Stripe](https://stripe.com)**: Secure payment processing for credit and debit cards.
- **[Adyen](https://www.adyen.com)**: Scalable global payments supporting multiple payment methods.
- **[Nicky.me](https://nicky.me)**: A user-friendly crypto payment provider offering fast and secure transactions.
- **[NOWPayments](https://nowpayments.io)**: An additional option for accepting cryptocurrency payments.

### Required Configuration Variables

Modify the default values in the `.env` file with your specific credentials as shown below:

```env
STRIPE_SECRET_KEY='stripe-secret-key'
STRIPE_PUBLISHABLE_KEY='stripe-publishable-key'

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

After saving the file, clear the cache using the **Clear Cache** button located in the **Server Health** page of the Namingo Control Panel.

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
   - **[Stripe](https://stripe.com)**: Secure payment processing for credit and debit cards.
   - **[Adyen](https://www.adyen.com)**: Scalable global payments supporting multiple payment methods.
   - **[Nicky.me](https://nicky.me)**: A user-friendly crypto payment provider offering fast and secure transactions.
   - **[NOWPayments](https://nowpayments.io)**: An additional option for accepting cryptocurrency payments.

4. **Complete the Payment**  
   - For **Stripe** and **Adyen**: You will be redirected to the secure payment page to complete the transaction.
   - For **Nicky.me** and **NOWPayments**:
     - After checkout, you will be redirected to a payment status page.
     - Save the link to monitor the payment status.
     - Reload the page after the payment is confirmed to update your balance.

5. **Verify the Deposit**  
   Once the payment is completed and verified, the deposited amount will reflect in your account balance.

---

For additional support or questions about the process, please contact Namingo Registry Support. We are here to assist you at every step.