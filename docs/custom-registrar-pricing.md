# Guide to Adding Custom Pricing per Registrar (Deprecated since v1.0.19)

**Since v1.0.19 this is done directly in the panel, Registrars menu**

To add custom pricing per registrar in the domain price and domain restore price tables using Adminer, follow these steps:

## 1. Access Adminer:

Log in to your Adminer interface with your database credentials.

## 2. Navigate to the Correct Table:

- For general pricing, select the `domain_price` table.
- For restoration pricing, select `the domain_restore_price` table.

## 3. Add a New Row:

Click on the "Insert" tab at the top of the page to add a new record.

## 4. Fill in the Details:

- tldid: Enter the ID corresponding to the TLD (Top-Level Domain) you want to set the price for.

- registrar_id: Enter the ID of the registrar for whom you are setting the custom pricing. This field is crucial for setting registrar-specific pricing.

- command (only in the domain price table): Select the operation you are pricing for: 'create', 'renew', or 'transfer'.

### Price Fields:

For domain price:

- Enter the prices for each term (e.g., m0 for 0 months, m12 for 12 months, etc.). Ensure to fill in at least the relevant fields.

For domain restore price:

- Enter the price in the price field.

## 5. Save the Entry:

Once all the necessary details are filled in, click "Save" or "Insert" at the bottom of the form.

## Important Notes:

- *Unique Registrar Pricing:* Ensure that you specify the registrar_id correctly to apply the custom pricing for that specific registrar. **Leaving this field NULL will cause issues with the default price.**

- *Operation Specificity:* In the domain price table, the command field determines the specific operation (create, renew, transfer) the pricing applies to. Make sure to select the correct operation.

- *Accuracy:* Double-check the pricing fields to ensure that they are accurately filled in, as these will directly affect billing.

By following these steps, you can effectively manage custom pricing for different registrars using Adminer.