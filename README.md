# NordName FOSSBilling Registrar Module

FOSSBilling registrar adapter for [NordName](https://nordname.fi/) Domain API v3.

## Requirements

- FOSSBilling with domain registration enabled
- NordName reseller account with API access
- Outbound HTTPS from your FOSSBilling server to `api.nordname.fi` (production) or `api.ote.nordname.fi` (OTE/sandbox)
- A pre-created NordName contact for admin/tech/billing use — see [Preparing the auxiliary contact](#preparing-the-auxiliary-contact) below

## Installation

1. **Download or clone** this repository.

2. **Copy the adapter file** into your FOSSBilling installation:

   ```bash
   cp src/library/Registrar/Adapter/Nordname.php /path/to/fossbilling/src/library/Registrar/Adapter/Nordname.php
   ```

3. **Copy the extension module** for cron-based WHOIS sync:

   ```bash
   cp -r src/modules/Nordname /path/to/fossbilling/src/modules/Nordname
   ```

4. **Install the extension module** — in FOSSBilling admin, go to **Extensions**, find **NordName registrar**, and install and activate it. Run cron once after activation so FOSSBilling discovers the hook (see [Event Hooks — troubleshooting](https://docs.fossbilling.org/extensions-and-development/event-hooks#troubleshooting-hook-discovery)).

5. **Open the FOSSBilling admin area** and go to **System → Domain Management** (or **Domain registration**, depending on your FOSSBilling version) → **Registrars** tab.

6. **Create a new registrar** — FOSSBilling should list **Nordname** as an available adapter once the file is in place.

7. **Configure the registrar** — see [Module configuration](#module-configuration) below.

8. **Assign TLDs** — link the TLDs you sell to this registrar and set registration, transfer, and renewal pricing.

9. **Create domain products** — set up domain products in FOSSBilling as usual, pointing them at the configured TLDs.

For a full verification workflow after setup, see the [Testing checklist (OTE)](#testing-checklist-ote) below.

## Module configuration

Open the Nordname registrar in FOSSBilling admin and configure the adapter settings:

| Setting | Required | Description |
|---------|----------|-------------|
| **API Key** | Yes | Your NordName reseller API key. Sent as `Authorization: token {key}`. Validated on first use via `GET /tld?type=TLDList`. |
| **Admin/tech/billing contact ID** | Yes | Contact ID of an existing NordName contact with `is_registrant = false`. Used as admin, tech, and billing contact on all registrations and transfers. Validated via `GET /contact/{id}`. |
| **Sandbox mode** | No | `No (production)` uses `https://api.nordname.fi/api/v3/`. `Yes (OTE)` uses `https://api.ote.nordname.fi/api/v3/`. Enable OTE for testing before going live. |
| **Registrant contact language** | No | `en`, `fi`, or `sv`. Set on newly created registrant contacts; controls the language NordName uses for email/SMS communication to end users. Default: `en`. |
| **Renewal date offset (days)** | No | When set, the cron job sets each synced order's renewal date to the domain expiration date minus this many days. Leave empty to leave order renewal dates unchanged. `0` sets renewal to the domain expiration datetime exactly. `7` sets renewal one week before expiration. |

FOSSBilling also provides an **Enable Test Mode** toggle on the registrar page. This is separate from the NordName **Sandbox mode** setting above. The NordName sandbox setting is what switches API endpoints between production and OTE; the adapter does not use FOSSBilling's test mode to change the API URL.

### Preparing the auxiliary contact

Before configuring the module, create the admin/tech/billing contact in NordName:

1. Log in to the NordName reseller panel — use production or OTE depending on whether you will enable **Sandbox mode** in FOSSBilling.
2. Create a contact for your business to serve as admin, tech, and billing contact. This contact must **not** be a registrant contact (`is_registrant` must be `false`). Enter as many of the optional fields as possible, for best compatibility across TLDs.
3. Copy the contact ID from the NordName contact list or via the API (`GET /contact`).
4. Paste the ID into **Admin/tech/billing contact ID** in FOSSBilling.

NordName requires separate registrant and admin/tech/billing contacts. Registrant contacts are created automatically for each domain order; the auxiliary contact is reused across all registrations and transfers.

### Configuration validation

When the adapter is first used, it validates your settings. The following errors may be returned:

- **Missing API key or auxiliary contact ID** — both fields are required before the registrar can be used.
- **Invalid API key** — the adapter could not reach the NordName API or `GET /tld` failed.
- **Invalid auxiliary contact ID** — `GET /contact/{id}` failed or the contact does not exist.
- **Auxiliary contact is a registrant** — the configured contact has `is_registrant = true`; use a non-registrant contact instead.

## Cron sync

The **NordName registrar** extension module registers an `onBeforeAdminCronRun` hook that runs on every admin cron execution. For each **active** domain order using the Nordname registrar, it:

1. Calls FOSSBilling's `syncWhois()` to refresh domain registration data (expiry, contacts, lock status, privacy, and related fields) from the NordName API
2. Syncs nameservers (`ns1`–`ns4`) on the domain service from the registrar data returned by WHOIS sync (or via `getDomainDetails()` as a fallback)
3. Sets the order's `activated_at` timestamp to the current datetime **only if it is empty** and the domain was found successfully at NordName
4. Optionally sets the order's renewal date (`expires_at`) based on the domain expiration date — see [Renewal date offset](#module-configuration) in the registrar adapter settings

Orders using other registrars are skipped. If a domain is not yet available at NordName (for example, a transfer still in progress), the sync fails for that order, an error is logged, and `activated_at` is left unchanged.

The renewal date offset is read from each NordName registrar instance's adapter configuration, so multiple NordName registrars can use different offsets if needed. It is applied only after a successful WHOIS sync, using the domain's `expires_at` value returned from NordName.

## TLD import / sync

The extension module provides an admin tool at **Extensions → NordName registrar** (or `/admin/nordname`) for importing or updating TLD pricing from NordName.

NordName wholesale prices are always in **EUR**. FOSSBilling TLD prices are stored in your **default currency**. When the default currency is not EUR, the tool converts wholesale EUR amounts using the **EUR exchange rate** configured under **System → Settings → Currency settings** before applying your margin. If EUR is missing or has no conversion rate, load and import are blocked with a link to the currency settings page.

1. Select a configured NordName registrar instance
2. Click **Load TLDs** to fetch the catalog and wholesale prices from NordName (`GET /tld?type=TLDPriceList`, paginated)
3. Choose whether to use **promotional** or **standard** wholesale prices as the base
4. Set a **fixed profit margin** in your default currency and/or **percent profit margin**
5. Select TLDs from the table and click **Import / sync selected**

For each selected TLD:

- Registration and renewal sell prices are based on NordName wholesale price (EUR) × minimum registration years (fetched per TLD during import via `GET /tld/{tld}`)
- Transfer price uses the wholesale transfer price as-is
- Wholesale EUR is converted to the default currency, then: sell price = converted base + fixed margin + converted base × percent / 100
- Existing FOSSBilling TLDs are updated (`tldUpdate`); new TLDs are created (`tldCreate`) with register and transfer enabled

The admin table labels wholesale columns in **EUR** and sell columns in your default currency.

API credentials, sandbox mode, registrant language, and renewal date offset are configured under **System → Domain Management → Registrars**, not on this page.

Requires the `servicedomain.manage_tlds` admin permission.

## Post-configuration setup

After saving the registrar settings:

- [ ] Import TLDs via **Extensions → NordName registrar** or create them manually under Domain Management
- [ ] Set registration, transfer, and renewal prices (or use the TLD import tool to pull wholesale prices from NordName with your margin applied)
- [ ] Assign the Nordname registrar to each TLD
- [ ] Enable **Sandbox mode** and test with an OTE API key before switching to production
- [ ] Disable sandbox and use your production API key when ready to go live
- [ ] Run through the [Testing checklist (OTE)](#testing-checklist-ote)

## Supported operations

| Operation | NordName API |
|-----------|--------------|
| Domain availability check | `GET /domain/availability` |
| Transfer availability check | `GET /domain/availability` |
| Domain registration | `POST /domain/register/{domain}` |
| Domain transfer | `POST /domain/transfer/{domain}` |
| Domain renewal | `POST /domain/{domain}/renew` |
| Domain details | `GET /domain/{domain}` |
| Nameserver update | `PUT /domain/{domain}/change_nameservers` |
| Contact update | `POST /contact/{id}` |
| EPP / auth code | `PUT /domain/{domain}/retrieve_auth_code` |
| Privacy protection | `PUT /domain/{domain}/feature/privacy` |
| Transfer lock / unlock | `PUT /domain/{domain}/feature/transfer_lock` |

Domain deletion is not supported by the NordName API and will return an error.

## Contact field mapping

When registering or transferring domains, the adapter:

1. Validates registrant contact data via `POST /contact?validate_for_tld={tld}&validate_for_type=registrant`
2. Creates the registrant contact with `is_registrant=true`
3. Uses your configured auxiliary contact for admin, tech, and billing roles

FOSSBilling contact fields are mapped as follows:

| FOSSBilling field | NordName field |
|-------------------|----------------|
| `company` | `company`; sets `registrant_type` to `Company` if set, otherwise `Private Person` |
| `birthday` | `birth_date` |
| `company_number` | `register_number` |
| `document_nr` | `id_number` |

Registrant name changes on existing domains are not supported via contact update (NordName requires a domain trade). The adapter preserves the existing registrant first/last name when updating other contact fields.

## Premium domains

Premium domains (non-standard pricing) are rejected with an error during availability checks, registration, transfer, and renewal.

## Limitations

- There is no scheduled/automatic TLD price sync, only on-demand import via the extension admin page is supported
- There is no support for automatic provisioning under TLD extensions which require additional contact fields or documents to be filled, beyond the standard contact fields
- There is no support for DNS zone management, domain owner changes or dropcatching
- Domain transfer statuses are not polled

TLD-specific requirements not covered by standard contact fields will surface as API validation errors during registrant contact validation.

## Testing checklist (OTE)

Use **Sandbox mode** with a NordName OTE API key. See [Post-configuration setup](#post-configuration-setup) for the recommended order of operations.

- [ ] Configure registrar with valid API key and auxiliary contact ID
- [ ] Verify invalid auxiliary contact (registrant contact) is rejected on save/use
- [ ] Check domain availability for a free and a taken domain
- [ ] Register a test domain with registrant contact validation
- [ ] Change nameservers
- [ ] Enable and disable privacy protection
- [ ] Lock and unlock transfer
- [ ] Retrieve EPP code
- [ ] Renew domain
- [ ] Transfer a domain (with valid auth code)
- [ ] Update registrant contact details (address, email, phone)

## API reference

- [FOSSBilling registrar integration guide](https://docs.fossbilling.org/extensions-and-development/guides/creating-a-registrar-integration/)
- NordName API v3 specification: [`swagger.yaml`](https://app.nordname.eu/en/api-docs)

## Support

If there are any questions or feature requests for this module, email support (at) nordname.com.