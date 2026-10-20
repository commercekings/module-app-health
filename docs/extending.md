# Extending App health

Start with the [README](../README.md#extending).

Stable extension points (`@api`); the names are in the `CommerceKings\AppHealth` namespace. In every pool the di.xml
item name is the code (`[a-z0-9_]`): a check's for its state, its settings (`commercekings_app_health/<code>/…`) and
its messages, a log source's for its cursor, a validator's for `validator="<code>"` in rules.

| For | Use |
|---|---|
| A check | `Api\CheckInterface` (`getLabel()`, `run($websiteIds)`) in `Model\CheckPool` "checks": results by scope – `[CheckInterface::SHOP => $result]` for the whole shop (to the default recipient), one per website id asked about for something each website has (to its recipient) |
| What a check returns | `Api\Data\CheckResult` – `ok()`, `warn()` or `fail()`, a title, a one-line detail of names and numbers, counts for the daily summary, gauges, notices (one-off news) – and its causes, `Api\Data\Cause` (code, detail, hint) via `withCauses()` |
| What to do | the hint of each `Api\Data\Cause`; `CheckResult::withHint($hint)` adds a check's one general hint where nothing says what to do (a problem without hinted causes, news without a "Check: …" line) |
| A check's on/off switch | `commercekings_app_health/<code>/enabled` (on when not set; a field in your `system.xml` shows it) |
| A log source | `Api\LogSourceInterface` (new data since a cursor, as `Api\Data\LogChunk`) and `Api\LogParserInterface` (data to `Api\Data\LogEntry` items) in `Model\Log\LogSourcePool` "sources", for the `system_log` or `exceptions` check; app health makes every entry safe itself (fingerprint, label, masking, file:line) |
| Sensitive-data rules, no PHP | `etc/app_health_rules.xml` in any module (schema `etc/app_health_rules.xsd`), merged by rule set and rule code: add a set or a rule, override a shipped one, or turn it off (`enabled="false"`) |
| A validator for rules | `Api\SensitiveDataValidatorInterface` (`getPattern()`, `isValid($match)`) in `Model\Sensitive\ValidatorPool` "validators"; rules use it as `validator="<code>"` (`type="validator"` for its pattern) |
| Rules in the admin | *Sensitive data in logs*: *Rule settings* (a set or rule on or off, its severity) and *Custom rules* (regex rules, checked on save) |
| Where messages go | *Alerts > Channel* (`slack` by default) and *Recipient*, *Website channels*: any Notification channel, so any provider module (Notification's `Api\ProviderInterface`) works; sent through Notification's queue as the types `app_health_alert`, `app_health_summary` and `app_health_status` |
| How a channel's messages look | `Api\TeamMessageRendererInterface` (`render(Api\Data\TeamMessageContent)`: a title, a severity, `Api\Data\TeamMessageSection` items with lines, list lines and `Api\Data\TeamMessageTable` tables, hints) in `Model\Message\RendererPool` "renderers", the item name the channel code: `slack` is `Model\Message\SlackRenderer` (mrkdwn); a channel without one gets plain text (`Model\Message\PlainTextRenderer`: no markup, "- " lists, aligned tables) |
| Status from outside Magento | any tool that writes `var/app-health/status-<source>.json` in the format above (version 1) – no PHP |
| When the hourly status is posted | `Api\StatusPolicyInterface` (`getIntervalMinutes($scopeId)`, kept within 15 to 180; `shouldPost(Api\Data\StatusReport)`), shipped as `Model\Status\DefaultPolicy`; replace it with a `<preference>`. The status is still delivered every interval (quietly when not posted); urgent alerts never ask the policy |

A check, in your module's `etc/di.xml`:

```xml
<type name="CommerceKings\AppHealth\Model\CheckPool">
    <arguments>
        <argument name="checks" xsi:type="array">
            <item name="erp_sync" xsi:type="object">Vendor\Erp\Model\Health\SyncCheck</item>
        </argument>
    </arguments>
</type>
```

```php
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Format;

class SyncCheck implements \CommerceKings\AppHealth\Api\CheckInterface
{
    public function getLabel(): string
    {
        return 'ERP sync';
    }

    public function run(array $websiteIds): array
    {
        $result = $this->lastSyncAge() > 3600
            ? CheckResult::fail('no ERP sync for ' . Format::duration($this->lastSyncAge()), [], 'ERP sync stopped')
            : CheckResult::ok();
        return [self::SHOP => $result->withHint((string)__('Is the ERP connector\'s cron job running?'))];
    }
}
```

A channel's look, in the channel module's `etc/di.xml` (e.g. a Teams module for its `teams` channel; App health
needs no change) – a complete example with its renderer: [examples/teams-renderer](examples/teams-renderer):

```xml
<type name="CommerceKings\AppHealth\Model\Message\RendererPool">
    <arguments>
        <argument name="renderers" xsi:type="array">
            <item name="teams" xsi:type="object">Vendor\NotificationTeams\Model\AppHealthRenderer</item>
        </argument>
    </arguments>
</type>
```

A rule set, in your module's `etc/app_health_rules.xml` – a set of its own, a shipped rule changed, a shipped set off:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:CommerceKings_AppHealth:etc/app_health_rules.xsd">
    <ruleset code="loyalty" label="Loyalty" severity="critical">
        <hint>Log the customer id instead of the loyalty card number.</hint>
        <rule code="loyalty_card" label="loyalty card number">
            <pattern><![CDATA[/\bLC-\d{8}\b/]]></pattern>
        </rule>
    </ruleset>
    <ruleset code="pci"><rule code="card_cvv" severity="warning"/></ruleset>
    <ruleset code="personal" enabled="false"/>
</config>
```

A status policy that posts only during opening hours – `etc/di.xml`
`<preference for="CommerceKings\AppHealth\Api\StatusPolicyInterface" type="Vendor\Shop\Model\OpeningHours"/>`:

```php
class OpeningHours implements \CommerceKings\AppHealth\Api\StatusPolicyInterface
{
    public function __construct(private readonly \CommerceKings\AppHealth\Model\Status\DefaultPolicy $default)
    {
    }

    public function getIntervalMinutes(int $scopeId): int
    {
        return $this->default->getIntervalMinutes($scopeId);
    }

    public function shouldPost(\CommerceKings\AppHealth\Api\Data\StatusReport $report): bool
    {
        $hour = (int)$report->now->format('G'); // the scope's time zone
        return $hour >= 8 && $hour < 20 && $this->default->shouldPost($report);
    }
}
```

How to use them – a run, the state, a log source and its parser, the rules: [how it works](how-it-works.md).
