# Sensitive data and masking

Start with the [README](../README.md).

## Sensitive data in logs

A log that holds card data, secrets or personal data is a security problem of its own (for card data, a PCI DSS
breach). Every new log entry – every level, every log source – is scanned before anything is masked, and a finding is
a high alert at once, with the rule, how often, the last time and where – never the value:

```
*Security: sensitive data in logs · Acme Shop · shop.example.com*

PCI: payment card number in system.log (3×, last 14:02)
Where: app/code/Vendor/Pay/Model/Gateway.php:120

What to do: Stop logging it at the source, then rotate or remove the log: card data in logs breaks PCI DSS.
```

| Rule set (severity) | Rules |
|---|---|
| `pci` – PCI (critical) | `card_number` 13–19 digits with a card scheme's prefix and a valid Luhn check digit; `card_cvv` cvv/cvc/cid fields; `card_track` magnetic stripe track data |
| `credentials` – Credentials (critical) | `private_key` PEM private keys; `jwt` JSON web tokens; `auth_header` Bearer/Basic authorization headers; `password_field` password=… / "password": …; `api_key` Stripe live keys, Slack tokens, AWS access keys, Google API keys, GitHub tokens |
| `personal` – Personal data (warning) | `iban` IBANs with a valid checksum; `bulk_emails` 5+ email addresses in one entry; `bulk_phones` 5+ phone numbers in one entry |

Country-specific ID formats and other rules can come as rule-set modules.

Add, change or turn off rules from a module (one XML file, no PHP), add a validator, or set them in the admin
(*Rule settings*, *Custom rules* – a custom rule is checked on save: it compiles, at most 300 characters, no nested
quantifiers, fast on long lines and on a realistic 80 KB log sample): see [Extending](extending.md).

## What is masked

A log line never leaves the server as it is. An error is shown by its label – the exception class, else
the message's first 3 words – and, in alerts, file:line (relative to the Magento root). *Add the masked log message* is
**off by default**: only the error's type, count, time and file:line leave the server. Switched on, alerts also carry
a masked one-line message – more useful to read, but names and other free text in it may still show.

Always masked, in the label and the message, as `***`:

- the value after a secret's key word – **password, pass, pwd, secret, token, key, api key, pin, cvv, cvc** – whatever
  its length ("password hunter2", "pin=12");
- one or two words after a person's key word – **customer, name, first/last name, user, recipient, cardholder, buyer,
  contact** ("customer jane doe could not pay");
- **emails, phone numbers, numbers of 4 digits or more, IPv4 and IPv6 addresses, tokens, URLs and quoted values**.

- **The masked message:** its first 160 characters, one line. PHP's function prefixes go first, and a long message
  keeps its trailing reason ("… (Connection refused)"). **Not masked:** other free text, e.g. a capitalized name with no
  key word before it, or SQL. The latest per error is kept in the module's state (at most 500 errors).
- **The label:** at most 3 words, with URLs, emails, quoted values, hex strings, numbers and paths after their first
  segment – and any capitalized word after the first (often a name) – as `***`. The daily summary shows labels only.
- **Off** (*Add the masked log message* = No, the default): labels only, everywhere; a cron job's error text is not read
  either.
