# Lab 5 — Assignment 2: DynamoDB Notes App for the Lab 4 Matomo App

Deploys the opposite database type from Lab 4 (NoSQL) on Amazon DynamoDB and connects it to a
new standalone app deployed alongside Matomo on the same Lab 4 EC2 instance, per the brief's
Case B requirements.

- **App URL**: http://3.111.58.245:8081/ (EC2 has no Elastic IP — this changes on every
  instance restart; check the EC2 console for the current address)
- **AWS account**: calista-labs (386347568553), region ap-south-1 (Mumbai)
- **EC2 instance**: `matomo-lab4` (`i-077933894a1f47c75`) — same instance as Lab 4/Assignment 1
- **DynamoDB table**: `matomo-site-notes`, on-demand capacity
- **IAM role**: `matomo-dynamodb-role`, attached directly to the EC2 instance

## Why a new standalone app, not modifying Matomo

The brief allows either modifying the existing app or deploying a new one on the same
instance. Matomo (PHP analytics software) has no natural use for a NoSQL side-table, so a
small standalone PHP tool — "site operational notes" — was built instead: a place to record
freeform ops notes per tracked site. It runs as its own Nginx site + PHP-FPM pool on port
8081, entirely separate from Matomo's own config on port 80, so nothing about the working
Lab 4/Assignment 1 deployment was touched.

## Architecture

```
Browser ──HTTP:8081──> EC2 (matomo-lab4)
                          Nginx (port 8081) + dedicated PHP-FPM pool "dynamo-notes"
                          /var/www/dynamo-notes — AWS SDK for PHP
                                │
                                │ IAM role credentials via instance metadata (IMDSv2)
                                │ no hardcoded AWS keys anywhere in the app
                                ▼
                       DynamoDB table: matomo-site-notes
                       Partition key: site_id (N)   Sort key: note_id (S)
                       IAM role: matomo-dynamodb-role
                       IAM policy: matomo-dynamodb-policy — scoped to this one table only
```

## Deployment steps

1. **Create the DynamoDB table** (AWS Console → DynamoDB → Create table):
   - Table name: `matomo-site-notes`
   - Partition key: `site_id` (Number), Sort key: `note_id` (String)
   - Default settings (on-demand capacity)

2. **Create a least-privilege IAM policy**, scoped to this table's ARN only — see
   [`iam-policy.json`](./iam-policy.json). Grants `GetItem` / `PutItem` / `UpdateItem` /
   `DeleteItem` / `Query` / `Scan`, nothing else, on exactly one resource.
   > **Note:** the `calista-labs` IAM user deliberately holds only `IAMReadOnlyAccess` (set
   > during Assignment 1's IAM fix), so creating this policy and the role below required
   > signing in as the account root user for this one step — the same pattern as the original
   > RDS/DynamoDB permission fix.

3. **Create the IAM role** (`matomo-dynamodb-role`), trusted entity type EC2, with
   `matomo-dynamodb-policy` attached.

4. **Attach the role to the running instance** — EC2 → Instances → `matomo-lab4` → Actions →
   Security → Modify IAM role. Applied live, no instance restart required.

5. **Install the AWS SDK for PHP** via Composer on the instance, in a fresh directory kept
   entirely separate from Matomo's:
   ```bash
   sudo apt install -y composer
   mkdir -p /var/www/dynamo-notes && cd /var/www/dynamo-notes
   composer require aws/aws-sdk-php
   ```

6. **Build the app** — see [`index.php`](./index.php) for the deployed source. Uses
   `Aws\DynamoDb\DynamoDbClient` with the SDK's default credential provider chain, which
   resolves to the instance's IAM role automatically — no access key or secret key anywhere
   in the code.

7. **Give it its own PHP-FPM pool and Nginx site** on port 8081, so it never touches Matomo's
   working Nginx config on port 80. Required opening port 8081 at **both** firewall layers
   this account uses: the EC2 Security Group *and* the instance's own `ufw` — a rule only in
   one place still leaves the port unreachable from outside, which is exactly what happened
   the first time (the Security Group rule existed but `ufw` had never heard of port 8081).

## CRUD proof

Demonstrated all four operations live through the running app at
`http://3.111.58.245:8081/`:

| Operation | Action taken | Result |
|---|---|---|
| Create | Added note "RDS migration verified" for site 1 via the form | New item written to `matomo-site-notes` |
| Read | Page reloaded after creation | Note listed immediately — "Notes (1)" |
| Update | Clicked "Mark resolved" | `resolved` flipped false → true, reflected instantly |
| Delete | Clicked "Delete", confirmed | Item removed — "Notes (0)" |

## Attribute-type proof

The assignment requires 5+ distinct DynamoDB attribute types on a stored item. The item
created above carries all of these:

| Attribute | Type | Example value |
|---|---|---|
| `site_id` | Number (N) | `1` |
| `note_id` | String (S) | `note_6ab29b3b60d8e0.77191854` |
| `title` / `body` | String (S) | `"RDS migration verified"` |
| `created_at` | Number (N) | `1790090043` |
| `resolved` | Boolean (BOOL) | `false` |
| `tags` | List (L) | `["migration", "verified"]` |
| `meta` | Map (M) | `{"author": "calista", "priority": "high"}` |

Five distinct types: **N, S, BOOL, L, M** — confirmed directly in the DynamoDB console's item
detail view, not just inferred from the app's form.

Full screenshot evidence (table creation, IAM policy/role, EC2 role attachment, the live app,
all four CRUD steps, and the item detail view showing all attribute types) is in
[`Lab5-Assignment2-Screenshots.docx`](./Lab5-Assignment2-Screenshots.docx) in this repo — see
the 1–2 page PDF report (`Lab-05-Assignment2-Report.pdf`) for the condensed writeup.

## Security summary

- DynamoDB reachable only via the EC2 instance's IAM role (`matomo-dynamodb-role`) — **no
  hardcoded AWS access keys** anywhere in the app or its config
- IAM policy scoped to exactly one table ARN, not blanket `dynamodb:*` access
- The app's own port (8081) is restricted to a single admin IP at the Security Group level,
  not `0.0.0.0/0`, and is opened at both the Security Group and the host `ufw` firewall
- No secrets committed to this repo — the app makes no use of static credentials to begin with
