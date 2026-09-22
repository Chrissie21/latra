# LATRA ACCESS & COMPLIANCE MONITORING MODULE

## 1. Project Context

We already have a fully developed **Wialon → LATRA integration platform**.

The existing system is responsible for:

```text
Wialon
   ↓
Wialon API / Real-Time Data
   ↓
Our Middleware / Integration Platform
   ↓
Data Processing & Normalization
   ↓
Event Detection
   ↓
Activity Classification
   ↓
LATRA Data Mapping
   ↓
LATRA API
```

The existing system already collects data from Wialon and automatically processes and submits the required information to LATRA.

**Do NOT rebuild this integration.**

The new task is to extend the existing platform with a **restricted LATRA-facing access interface** because LATRA has requested access to our system so they can:

1. Monitor how the Wialon → LATRA integration operates.
2. Inspect real-time tracking/device information.
3. Interrogate tracking devices.
4. Manage approved tracking-device parameters, such as speed limits.
5. Monitor GPS data being received.
6. Verify GPS data quality and completeness.
7. Monitor data being processed by our middleware.
8. Monitor data being submitted to LATRA.
9. Investigate failed or delayed submissions.
10. Audit the integration and system activity.

The result should be a **LATRA Access & Compliance Monitoring Module** added to the existing application.

---

# 2. Main Objective

Build a secure, professional, read-mostly interface for authorized LATRA users.

The portal must provide LATRA with visibility into the existing system without exposing:

* Wialon credentials
* LATRA API credentials
* Database credentials
* Server credentials
* Source code
* Internal administration
* Unrestricted database access
* Internal system configuration
* Other sensitive infrastructure

LATRA should interact with the system through controlled application interfaces and APIs.

---

# 3. Interpret LATRA's Requirements

The implementation must directly address these two requirements.

## Requirement 1

> Provide a mechanism/interface where LATRA can directly interrogate and manage real-time tracking devices and update parameters such as speed limit at any time.

Translate this into the following functionality:

* Device list
* Device status
* Vehicle information
* Real-time GPS information
* Device health
* Device interrogation
* Current device parameters
* Approved parameter changes
* Command status
* Command history
* Confirmation before sensitive changes
* Complete audit trail

---

## Requirement 2

> Provide access and information to LATRA at any given time for the GPS data receiving and integration/middleware platform for auditing and quality assurance purposes.

Translate this into:

* Integration dashboard
* Wialon connection status
* Middleware status
* Scheduler status
* GPS receiving status
* GPS data quality
* Data processing status
* LATRA API status
* LATRA submission history
* Failed submissions
* Processing delays
* Event processing
* Audit logs
* Compliance reports
* GPS history
* Data traceability

---

# 4. Critical Development Instruction

Before changing anything:

**Inspect the existing codebase and database.**

Identify and reuse the existing:

* Wialon integration
* Wialon session management
* Real-time event engine
* Vehicle data
* Device data
* GPS records
* Event records
* Activity records
* LATRA mapping
* LATRA submission logic
* Scheduler
* Background workers
* Integration logs
* Error logs
* Existing authentication
* Existing database schema
* Existing UI components

Do not create duplicate systems when an existing implementation already provides the required information.

For example:

**Do not create another Wialon polling service.**

The LATRA interface should read from the existing real-time data pipeline.

**Do not create another LATRA submission engine.**

The portal should display information from the existing LATRA submission process.

---

# 5. High-Level Architecture

Extend the existing architecture like this:

```text
                         ┌─────────────────────────────┐
                         │       LATRA USER            │
                         └──────────────┬──────────────┘
                                        │
                                        ▼
                         ┌─────────────────────────────┐
                         │ LATRA ACCESS & COMPLIANCE   │
                         │           MODULE            │
                         │                             │
                         │ Dashboard                   │
                         │ Live Monitoring             │
                         │ Devices                     │
                         │ GPS & Events                │
                         │ Integration Monitoring      │
                         │ Audit & Reports             │
                         └──────────────┬──────────────┘
                                        │
                                        ▼
                         ┌─────────────────────────────┐
                         │     CONTROLLED PORTAL API   │
                         └──────────────┬──────────────┘
                                        │
                    ┌───────────────────┼───────────────────┐
                    ▼                   ▼                   ▼
             Existing Data       Existing Services    Existing Logs
                    │                   │                   │
                    └───────────────────┼───────────────────┘
                                        ▼
                         ┌─────────────────────────────┐
                         │ EXISTING WIALON → LATRA     │
                         │        PLATFORM             │
                         └──────────────┬──────────────┘
                                        │
                             ┌──────────┴──────────┐
                             ▼                     ▼
                          WIALON                 LATRA
```

---

# 6. LATRA User Role

Create a dedicated role:

```text
LATRA_USER
```

Optionally support:

```text
LATRA_VIEWER
LATRA_INSPECTOR
LATRA_ADMIN
```

Do not give LATRA users internal administrator privileges.

---

# 7. Permissions

Create granular permissions.

Example:

```text
dashboard.view
vehicles.view
devices.view
gps.view
events.view
integration.view
reports.view
audit.view
device.interrogate
device.parameters.view
device.parameters.update
device.commands.view
```

Recommended permissions:

| Capability             | Viewer |  Inspector | Admin |
| ---------------------- | -----: | ---------: | ----: |
| Dashboard              |      ✓ |          ✓ |     ✓ |
| Live Monitoring        |      ✓ |          ✓ |     ✓ |
| Vehicles               |      ✓ |          ✓ |     ✓ |
| Devices                |      ✓ |          ✓ |     ✓ |
| GPS Data               |      ✓ |          ✓ |     ✓ |
| Events                 |      ✓ |          ✓ |     ✓ |
| Integration Monitoring |      ✓ |          ✓ |     ✓ |
| Audit Logs             |      ✗ |          ✓ |     ✓ |
| Reports                |      ✓ |          ✓ |     ✓ |
| Interrogate Device     |      ✗ |          ✓ |     ✓ |
| View Parameters        |      ✓ |          ✓ |     ✓ |
| Change Parameters      |      ✗ | ✗/optional |     ✓ |
| Manage LATRA Users     |      ✗ |          ✗ |     ✓ |

Authorization must be enforced on the backend.

Hiding a button is not authorization.

---

# 8. Main Portal Navigation

Create the following navigation:

```text
LATRA ACCESS
│
├── Dashboard
│
├── Live Monitoring
│
├── Vehicles & Devices
│
├── GPS & Events
│
├── Integration Monitoring
│
├── Audit Logs
│
└── Reports
```

If the existing application already has navigation, integrate this module into the existing design instead of creating an unrelated application.

---

# 9. Dashboard

Create a professional LATRA monitoring dashboard.

The dashboard should answer:

> "Is the Wialon → LATRA system currently working correctly?"

Display:

```text
TOTAL VEHICLES
398

ONLINE DEVICES
365

OFFLINE DEVICES
33

GPS DATA HEALTH
99.8%

LATRA SUBMISSION SUCCESS
99.9%

ACTIVE EVENTS
17
```

Use real database values.

Never hard-code these numbers.

---

## 9.1 System Health

Create three primary status cards:

```text
WIALON

● CONNECTED

Last communication:
08:42:31
```

```text
MIDDLEWARE

● RUNNING

Last processing:
08:42:32
```

```text
LATRA API

● HEALTHY

Last successful submission:
08:42:33
```

Also show:

```text
Scheduler
● RUNNING

Database
● HEALTHY

Real-Time Event Engine
● RUNNING
```

Use existing system health information wherever available.

---

# 10. Integration Summary

Show:

```text
TODAY'S DATA

GPS Records Received
1,284,562

Records Processed
1,284,562

Records Sent to LATRA
1,281,920

Successful
1,280,871

Failed
1,049

Pending
0
```

Calculate values from the actual system.

---

# 11. Recent Activity

Display recent integration activity.

Example:

```text
08:42:31
GPS RECEIVED
T 123 ABC

08:42:31
EVENT PROCESSED
Movement / Logging

08:42:32
SUBMITTED TO LATRA
T 123 ABC

08:42:33
LATRA RESPONSE
SUCCESS

08:42:35
ENGINE ON
T 456 DEF
```

The activity feed should update automatically.

---

# 12. Live Monitoring

Create a real-time vehicle monitoring interface.

Layout:

```text
┌────────────────────────────────────────────────────────┐
│ Search vehicle                                         │
│ [ T 123 ABC                                    ]       │
├──────────────────────┬─────────────────────────────────┤
│                      │                                 │
│ VEHICLES             │                                 │
│                      │                                 │
│ ● T 123 ABC          │             MAP                 │
│ ● T 456 DEF          │                                 │
│ ● T 789 GHI          │                                 │
│                      │                                 │
└──────────────────────┴─────────────────────────────────┘
```

Use Leaflet or the existing map implementation.

---

# 13. Vehicle Marker Information

Clicking a vehicle should show:

```text
T 123 ABC

Device
Tracker-001

IMEI
860123456789012

Status
ONLINE

Latitude
-3.386900

Longitude
36.683000

Speed
62 km/h

Heading
120°

Satellites
12

HDOP
0.8

External Power
CONNECTED

Battery
87%

Last GPS Update
5 seconds ago
```

Use actual data.

---

# 14. Vehicles & Devices

Create a searchable table:

| Vehicle | Device | IMEI | Status | Speed | GPS | Battery | Last Update | Actions |
| ------- | ------ | ---- | ------ | ----: | --- | ------: | ----------- | ------- |

Actions:

```text
View
Interrogate
History
```

If the user has the required permission:

```text
Manage
```

Filters:

* Vehicle
* Device
* IMEI
* Status
* GPS status
* Battery status

Use server-side pagination for large datasets.

---

# 15. Device Details

Create a detailed device page.

## Vehicle Information

```text
Registration
Vehicle type
Operator
Status
```

## Device Information

```text
Device ID
IMEI
Model
Firmware
Installation status
```

## Current GPS

```text
Latitude
Longitude
Speed
Heading
Altitude
Satellites
HDOP
GPS timestamp
Server timestamp
```

## Connectivity

```text
Wialon status
Last communication
GSM status
External power
Internal battery
```

## Device Health

```text
GPS
GSM
POWER
BATTERY
COMMUNICATION
```

Each should have:

```text
HEALTHY
WARNING
OFFLINE
CRITICAL
```

---

# 16. Device Interrogation

Implement a controlled **Interrogate Device** feature.

The purpose is to allow an authorized LATRA user to request the latest available device information.

Button:

```text
[ INTERROGATE DEVICE ]
```

Show:

```text
DEVICE INTERROGATION

Request ID:
REQ-20260908-000124

Device:
860123456789012

Requested:
08:45:21

Response:
08:45:22

Status:
SUCCESS
```

Then:

```text
Device Status
ONLINE

GPS
VALID

External Power
CONNECTED

Battery
87%

Speed
61 km/h

Latitude
-3.386900

Longitude
36.683000
```

Every interrogation must create an audit record.

---

# 17. Device Parameter Management

LATRA specifically requires the ability to manage approved device parameters.

Create a **Device Parameters** section.

Initially support only parameters that are actually supported by the existing platform/device integration.

Possible parameters:

```text
Maximum Speed
GPS Reporting Interval
Emergency Reporting Interval
Emergency Mode
```

Do not invent device capabilities.

If a parameter cannot actually be changed through the existing Wialon/device integration, display it as read-only.

---

# 18. Parameter UI

Example:

```text
DEVICE PARAMETERS

Maximum Speed
Current value: 80 km/h

New value:
[ 60 ] km/h


GPS Reporting Interval
Current value: 3 seconds

New value:
[ 3 ] seconds


Emergency Reporting Interval
Current value: 1 second

New value:
[ 1 ] second


Emergency Mode
Current value: ENABLED

New value:
[ ENABLED ▼ ]


[ SAVE CHANGES ]
```

---

# 19. Parameter Validation

Never allow arbitrary values.

Each parameter must have:

```text
Name
Data type
Minimum
Maximum
Allowed values
Unit
```

Example:

```text
Maximum Speed
Type: integer
Unit: km/h
Minimum: configurable
Maximum: configurable
```

Validation must happen on the server.

---

# 20. Parameter Change Workflow

Use this exact flow:

```text
LATRA USER
    ↓
Select Device
    ↓
Select Parameter
    ↓
Enter New Value
    ↓
Server Validation
    ↓
Authorization Check
    ↓
Confirmation
    ↓
Create Command
    ↓
Send Through Existing Integration
    ↓
Wait for Result/Acknowledgement
    ↓
Update Command Status
    ↓
Audit
```

Never bypass this process.

---

# 21. Confirmation Dialog

Before changing anything:

```text
CONFIRM PARAMETER CHANGE

Vehicle:
T 123 ABC

Device:
860123456789012

Parameter:
Maximum Speed

Current Value:
80 km/h

New Value:
60 km/h

Reason:
[ Regulatory speed adjustment ]

This action will be recorded in the audit log.

[ CANCEL ]     [ CONFIRM CHANGE ]
```

---

# 22. Command Status

Support:

```text
PENDING
SENT
ACKNOWLEDGED
SUCCESS
FAILED
TIMEOUT
CANCELLED
```

Example:

```text
COMMAND SUCCESSFUL

Command ID:
CMD-20260908-084521

Device:
860123456789012

Parameter:
Maximum Speed

Previous:
80 km/h

New:
60 km/h

Status:
SUCCESS

Completed:
08:45:22
```

If it fails:

```text
COMMAND FAILED

The device did not acknowledge the requested change.

Previous value remains unchanged.

Request ID:
REQ-20260908-084521
```

---

# 23. GPS & Events

Create a section where LATRA can inspect the GPS and event data being received by the system.

Subsections:

```text
GPS Explorer
GPS History
Events
Data Quality
```

---

# 24. GPS Explorer

Filters:

```text
Vehicle
[ All Vehicles ▼ ]

Date
[ 08/09/2026 ]

Time
[ 08:00 ] — [ 09:00 ]

Event
[ All Events ▼ ]

LATRA Status
[ All ▼ ]

[ SEARCH ]
```

Table:

| Time | Vehicle | Latitude | Longitude | Speed | Satellites | Event | LATRA Status |
| ---- | ------- | -------: | --------: | ----: | ---------: | ----- | ------------ |

Use server-side pagination.

---

# 25. GPS Record Details

Clicking a GPS record should show:

```text
GPS RECORD

Vehicle
T 123 ABC

Device
860123456789012

GPS Time
08:01:03

Server Time
08:01:04

Latitude
-3.386900

Longitude
36.683000

Speed
54 km/h

Heading
120°

Altitude
1387 m

Satellites
12

HDOP
0.8

Event
Movement / Logging
```

Also show processing information:

```text
Wialon Received
✓

Middleware Processed
✓

LATRA Payload Created
✓

Submitted to LATRA
✓

LATRA Response
SUCCESS
```

---

# 26. Data Traceability

This is a critical audit feature.

Allow LATRA to trace a record through the entire system:

```text
WIALON
  ↓
GPS RECEIVED
  ↓
NORMALIZED
  ↓
EVENT DETECTED
  ↓
STORED
  ↓
LATRA PAYLOAD CREATED
  ↓
SUBMITTED
  ↓
LATRA RESPONSE
```

Provide identifiers where available:

```text
GPS Record ID
Processing ID
Activity ID
Request ID
Command ID
LATRA Submission ID
```

This allows auditors to follow a specific record from source to destination.

---

# 27. Raw Data Inspection

Where appropriate, provide:

```text
[ VIEW RAW WIALON DATA ]

[ VIEW NORMALIZED DATA ]

[ VIEW LATRA PAYLOAD ]

[ VIEW LATRA RESPONSE ]
```

Raw data should be sanitized.

Never display:

* API tokens
* passwords
* authentication cookies
* internal secrets
* database credentials

---

# 28. GPS Data Quality

Create a dedicated **GPS Data Quality** dashboard.

Display:

```text
GPS DATA QUALITY

Overall
99.8%

Valid Records
1,280,871

Invalid Records
1,049

Delayed Records
87

Missing Records
0
```

Checks:

```text
Latitude                  PASS
Longitude                 PASS
Timestamp                 PASS
Speed                     PASS
Satellite Data            PASS
GPS Frequency              PASS
Duplicate Records          PASS
Invalid Coordinates        PASS
Missing GPS                WARNING
Delayed Data               WARNING
```

---

# 29. Compliance Rules

Compliance thresholds must be configurable.

Do not hard-code regulatory values throughout the application.

For example:

```text
Normal GPS reporting interval
Emergency GPS reporting interval
Maximum allowed delay
Maximum device offline duration
```

Use configuration/database values.

The UI should show:

```text
EXPECTED
OBSERVED
STATUS
```

Example:

```text
GPS REPORTING FREQUENCY

Expected:
≤ configured threshold

Observed:
45 seconds

Vehicles checked:
398

Vehicles with violations:
0

Status:
COMPLIANT
```

---

# 30. Events

Display events generated by the existing event engine.

Examples:

```text
Movement / Logging
Engine ON
Engine OFF
Speeding
Hard Braking
Hard Turning
Hard Acceleration
Panic Button
Internal Battery Low
External Power Disconnect
Device Offline
GPS Invalid
Communication Failure
```

Do not create a second event-detection engine.

Use the existing event engine.

---

# 31. Event Details

Each event should show:

```text
EVENT

Vehicle:
T 123 ABC

Device:
860123456789012

Event:
Speeding

Detected:
08:42:31

Speed:
97 km/h

Configured Limit:
80 km/h

Location:
-3.386900, 36.683000

Processing:
SUCCESS

LATRA Submission:
SUCCESS
```

---

# 32. Integration Monitoring

Create a dedicated page:

# Wialon → Middleware → LATRA

Display the complete system chain.

```text
WIALON
● HEALTHY

↓

GPS DATA RECEIVING
● HEALTHY

↓

EVENT ENGINE
● RUNNING

↓

MIDDLEWARE
● RUNNING

↓

SCHEDULER
● RUNNING

↓

LATRA API
● HEALTHY
```

---

# 33. Integration Metrics

Display:

```text
GPS RECEIVED TODAY
1,284,562

PROCESSED
1,284,562

LATRA SUBMISSIONS
1,281,920

SUCCESSFUL
1,280,871

FAILED
1,049

PENDING
0

AVERAGE PROCESSING TIME
540 ms
```

Use actual data.

---

# 34. LATRA Submission Logs

Create a detailed table:

| Time | Vehicle | Activity | Request ID | Status | HTTP Code | Processing Time |
| ---- | ------- | -------- | ---------- | ------ | --------: | --------------: |

Statuses:

```text
SUCCESS
FAILED
PENDING
RETRYING
TIMEOUT
```

Filters:

* Date
* Vehicle
* Activity
* Status
* Request ID

---

# 35. Submission Details

Clicking a submission should show:

```text
LATRA SUBMISSION

Request ID:
LATRA-20260908-084231-00125

Vehicle:
T 123 ABC

Activity:
Movement / Logging

Received from Wialon:
08:42:31.204

Processed:
08:42:31.500

Submitted to LATRA:
08:42:31.781

Response:
08:42:32.201

HTTP Status:
200

Result:
SUCCESS
```

Also show:

```text
GPS information
Normalized data
LATRA payload
LATRA response
```

where appropriate.

---

# 36. Failed Submissions

Create a dedicated failure view.

Display:

```text
FAILED LATRA SUBMISSIONS

Vehicle
T 456 DEF

Activity
Engine ON

Time
08:41:22

Error
LATRA API returned HTTP 500

Retry
YES

Current Status
RETRYING
```

Do not expose technical secrets.

Give enough information for LATRA auditors to understand what happened.

---

# 37. API Health

Create a health page for the LATRA integration.

Show:

```text
LATRA API

Status:
HEALTHY

Last Successful Request:
08:42:33

Requests Today:
1,284,562

Successful:
1,280,871

Failed:
1,049

Success Rate:
99.9%

Average Response:
540 ms
```

Do not display credentials.

---

# 38. Scheduler Monitoring

Use the existing scheduler.

Show:

```text
SCHEDULER

Status:
RUNNING

Last Tick:
08:42:30

Next Expected Tick:
08:43:00

Jobs Running:
3

Jobs Failed:
1

Jobs Pending:
0
```

If the existing scheduler has specific jobs, display the actual jobs rather than inventing them.

---

# 39. Audit Logs

Every important LATRA action must be logged.

Audit:

```text
Login
Logout
Dashboard access
Vehicle viewed
Device viewed
Device interrogation
GPS record viewed
Event viewed
Integration log viewed
Report generated
Parameter viewed
Parameter changed
Command created
Command executed
Command succeeded
Command failed
```

Audit table:

| Time | User | Action | Resource | Result |
| ---- | ---- | ------ | -------- | ------ |

Where appropriate also record:

```text
IP Address
User Agent
Request ID
Command ID
Previous Value
New Value
Reason
```

---

# 40. Parameter Audit

For every parameter change record:

```text
WHO
WHAT
WHEN
DEVICE
OLD VALUE
NEW VALUE
REASON
COMMAND ID
REQUEST ID
RESULT
```

Example:

```text
PARAMETER CHANGE

User:
LATRA Administrator

Vehicle:
T 123 ABC

Device:
860123456789012

Parameter:
Maximum Speed

Previous:
80 km/h

New:
60 km/h

Reason:
Regulatory speed adjustment

Requested:
08:45:21

Result:
SUCCESS
```

---

# 41. Audit Integrity

Normal users must not be able to:

* Edit audit records
* Delete audit records
* Modify historical command results

Audit records should be append-only from the application's perspective.

If retention/deletion is required for operational reasons, implement it as a restricted system-level process and preserve compliance requirements.

---

# 42. Reports

Create:

## GPS Data Report

Include:

```text
Vehicle
Device
IMEI
Timestamp
Latitude
Longitude
Speed
Heading
Satellites
Event
LATRA Status
```

## Device Health Report

Include:

```text
Vehicle
Device
IMEI
Online/Offline
Last Communication
Battery
Power
GPS
GSM
```

## Integration Report

Include:

```text
Records Received
Records Processed
Records Submitted
Successful
Failed
Pending
Average Processing Time
```

## Compliance Report

Include:

```text
GPS completeness
GPS validity
GPS reporting frequency
Emergency reporting
Device availability
LATRA submission success
Failed submissions
Delayed records
```

Allow export where supported by the existing application.

---

# 43. Real-Time Updates

The LATRA dashboard should not require manual browser refresh for important live information.

Use the existing real-time mechanism if available.

Otherwise use:

1. WebSocket
2. Server-Sent Events
3. Efficient polling

Use the simplest reliable option compatible with the current application.

Real-time updates should include:

```text
Vehicle location
Vehicle speed
Device status
New events
LATRA submission status
Alerts
Command status
```

Do not reload the entire page unnecessarily.

---

# 44. UI/UX

The interface should look like a professional **transport regulatory monitoring system**.

Design characteristics:

* Professional
* Clean
* Serious
* Responsive
* Information-focused
* Accessible
* Easy to audit
* Easy to understand
* Consistent

Avoid:

* Excessive animations
* Excessive gradients
* Consumer-style UI
* Decorative cards that don't contain useful information
* Unnecessary visual effects

Use:

* Tables
* Status indicators
* Charts where useful
* Maps
* Timeline views
* Filters
* Search
* Detail panels
* Confirmation dialogs

---

# 45. Status System

Use consistent statuses.

```text
ONLINE
OFFLINE
HEALTHY
WARNING
CRITICAL
SUCCESS
FAILED
PENDING
PROCESSING
RETRYING
TIMEOUT
```

Do not communicate status through color alone.

Use text/icons as well.

---

# 46. Security

This module provides external access to an existing production system.

Implement:

* Secure authentication
* Strong password hashing
* Session protection
* CSRF protection
* SQL injection protection
* Prepared statements
* XSS protection
* Input validation
* Output escaping
* Authorization checks
* Rate limiting
* Session timeout
* Secure cookies
* HTTPS
* Login protection
* Audit logging

Sensitive operations require additional authorization.

---

# 47. API Security

The portal must not directly expose internal services.

Use controlled endpoints.

Example:

```text
GET /api/latra/dashboard

GET /api/latra/vehicles

GET /api/latra/vehicles/{id}

GET /api/latra/devices

GET /api/latra/devices/{id}

GET /api/latra/devices/{id}/gps

GET /api/latra/devices/{id}/health

POST /api/latra/devices/{id}/interrogate

GET /api/latra/devices/{id}/parameters

POST /api/latra/devices/{id}/parameters

GET /api/latra/devices/{id}/commands

GET /api/latra/gps

GET /api/latra/events

GET /api/latra/integration/health

GET /api/latra/integration/submissions

GET /api/latra/audit

GET /api/latra/reports
```

Adapt endpoint naming to the existing project's conventions.

---

# 48. Do Not Expose Internal Infrastructure

LATRA must NOT receive:

```text
Wialon token
Wialon credentials
LATRA API token
Database credentials
Server credentials
Internal API secrets
SSH credentials
Source code
Environment variables
```

Do not return them from any API.

---

# 49. Database

Inspect the existing database before creating tables.

Reuse existing:

```text
vehicles
devices
gps data
events
activities
LATRA submissions
integration logs
scheduler information
```

Only create new tables when necessary.

Potential new tables:

```text
latra_users
latra_roles
latra_permissions
latra_user_roles
device_commands
device_parameter_changes
audit_logs
```

Use the project's existing naming conventions.

---

# 50. Device Command Table

If not already available, create a command table containing:

```text
id
device_id
vehicle_id
command_type
parameter_name
old_value
new_value
requested_by
requested_at
sent_at
acknowledged_at
completed_at
status
error_message
request_id
response_data
```

Do not duplicate an existing command system.

---

# 51. Audit Table

If not already available:

```text
id
user_id
action
resource_type
resource_id
description
old_value
new_value
reason
ip_address
user_agent
request_id
created_at
```

---

# 52. Performance

The platform may contain hundreds of vehicles and millions of GPS records.

Implement:

* Database indexes
* Server-side pagination
* Efficient queries
* Date indexes
* Vehicle/device indexes
* Request ID indexes
* Status indexes
* Avoid SELECT *
* Avoid loading huge GPS datasets
* Lazy loading where appropriate
* Efficient dashboard queries
* Background processing for large reports

The browser must never load millions of GPS records at once.

---

# 53. Error Handling

User-facing errors must be understandable.

Instead of:

```text
PDOException SQLSTATE...
```

show:

```text
Unable to retrieve GPS information.

The integration service did not respond within the expected time.

Request ID:
REQ-20260908-000124
```

Technical details should remain in server logs.

---

# 54. Loading States

Every major page must have a proper loading state.

Example:

```text
Loading vehicle information...
```

Use skeletons/spinners where appropriate.

---

# 55. Empty States

Example:

```text
NO RECORDS FOUND

No GPS records match the selected filters.

Try changing the vehicle or date range.

[ CLEAR FILTERS ]
```

---

# 56. Failure States

If Wialon is unavailable:

```text
WIALON CONNECTION

● OFFLINE

GPS data may be delayed.

Last successful communication:
08:41:22
```

If LATRA API is unavailable:

```text
LATRA API

● DEGRADED

Some submissions may be pending or retrying.

Last successful submission:
08:40:19
```

The interface must clearly distinguish between:

* No data
* Delayed data
* System failure
* API failure
* Device offline

---

# 57. Emergency Monitoring

If the existing system detects emergency events such as panic-button activation, display them prominently.

Example:

```text
EMERGENCY ACTIVE

Vehicle:
T 123 ABC

Event:
PANIC BUTTON

Started:
08:50:10

Current GPS:
-3.386900, 36.683000

GPS Update:
1-second interval

Status:
ACTIVE
```

Use the existing event engine and scheduler behavior.

Do not implement a separate emergency engine.

---

# 58. Data Retention

Respect the existing project's data retention policy.

Do not automatically delete existing GPS, event, submission, or audit records.

If retention policies exist, expose only the appropriate information to LATRA.

---

# 59. Testing

Perform testing against the actual existing platform.

Test:

### Authentication

* Valid LATRA login
* Invalid login
* Session timeout
* Logout
* Unauthorized access

### Authorization

* Viewer cannot change parameters
* Inspector cannot access restricted functions
* Administrator can perform authorized operations

### Device

* Device list
* Device details
* Device interrogation
* Parameter retrieval
* Parameter update
* Command success
* Command failure
* Command timeout

### GPS

* Current GPS
* Historical GPS
* Invalid GPS
* Missing GPS
* Delayed GPS

### Integration

* Wialon healthy
* Wialon unavailable
* LATRA API healthy
* LATRA API unavailable
* Submission success
* Submission failure
* Retry

### Audit

Verify every sensitive operation creates an audit record.

---

# 60. Important: Do Not Fake Functionality

Do not create fake:

```text
device status
GPS values
API health
submission counts
command responses
vehicle counts
```

If a required value cannot currently be obtained from the existing platform, identify the source that needs to be added rather than creating mock data.

The production UI must represent actual system state.

---

# 61. Implementation Strategy

Follow this order.

## Phase 1 — Existing System Analysis

Inspect:

```text
Project structure
Database
Authentication
Wialon integration
Event engine
Scheduler
GPS processing
Vehicle/device tables
LATRA submission
Logs
```

Produce a short internal architecture summary before implementation.

---

## Phase 2 — Data Mapping

Map existing data to the LATRA portal.

Create a mapping such as:

```text
Existing Source
      ↓
Portal Feature
```

For example:

```text
vehicle_state
      ↓
Live Monitoring

GPS records
      ↓
GPS Explorer

Event records
      ↓
Events

LATRA submission logs
      ↓
Integration Monitoring

Scheduler
      ↓
System Health

Existing device configuration
      ↓
Device Parameters
```

---

## Phase 3 — LATRA Authentication

Implement the restricted LATRA role and permissions.

---

## Phase 4 — Dashboard

Implement:

* System health
* Vehicle statistics
* GPS statistics
* LATRA submission statistics
* Recent activity

---

## Phase 5 — Live Monitoring

Implement:

* Vehicle map
* Device status
* Current GPS
* Vehicle details

---

## Phase 6 — Device Management

Implement:

* Device details
* Device interrogation
* Parameters
* Controlled parameter changes
* Commands
* Command history

---

## Phase 7 — GPS & Events

Implement:

* GPS Explorer
* GPS history
* GPS quality
* Events
* Event details

---

## Phase 8 — Integration Monitoring

Implement:

* Wialon status
* Middleware status
* Scheduler status
* LATRA API status
* Submission logs
* Failed submissions
* Processing metrics

---

## Phase 9 — Audit

Implement:

* User activity
* Device access
* Interrogations
* Parameter changes
* Commands
* Report generation

---

## Phase 10 — Reports

Implement:

* GPS report
* Device health report
* Integration report
* Compliance report

---

# 62. Final Portal Structure

The final LATRA-facing interface should approximately look like:

```text
┌──────────────────────────────────────────────────────────┐
│ LATRA VTS ACCESS                                         │
├───────────────┬──────────────────────────────────────────┤
│               │                                          │
│ Dashboard     │   SYSTEM HEALTH                          │
│               │                                          │
│ Live          │   Wialon       Middleware      LATRA     │
│ Monitoring    │   ● Healthy    ● Running       ● Healthy │
│               │                                          │
│ Vehicles      │   VEHICLES / DEVICES                     │
│ & Devices     │   398 Total    365 Online    33 Offline │
│               │                                          │
│ GPS & Events  │   GPS DATA                               │
│               │   99.8% Quality                          │
│ Integration   │                                          │
│ Monitoring    │   LATRA SUBMISSIONS                      │
│               │   99.9% Successful                       │
│ Audit Logs    │                                          │
│               │   RECENT ACTIVITY                        │
│ Reports       │   08:42 GPS received                     │
│               │   08:42 Submitted to LATRA               │
│               │   08:41 Engine ON                        │
│               │                                          │
└───────────────┴──────────────────────────────────────────┘
```

---

# 63. Final Acceptance Criteria

The feature is complete when an authorized LATRA user can log into the existing platform and independently:

### Monitor

* [ ] See total vehicles
* [ ] See online/offline devices
* [ ] See current vehicle locations
* [ ] See device status
* [ ] See GPS information
* [ ] See events
* [ ] See system health

### Interrogate

* [ ] Select a tracking device
* [ ] Request current information
* [ ] Receive the latest available device state
* [ ] See interrogation result
* [ ] See interrogation history

### Manage

* [ ] View approved device parameters
* [ ] Change authorized parameters
* [ ] Confirm changes
* [ ] See command status
* [ ] See success/failure
* [ ] See command history

### Audit GPS

* [ ] Search GPS records
* [ ] View historical GPS
* [ ] Inspect GPS quality
* [ ] Identify delayed/missing data
* [ ] Trace GPS data through the middleware
* [ ] See LATRA submission result

### Audit Integration

* [ ] See Wialon status
* [ ] See middleware status
* [ ] See event engine status
* [ ] See scheduler status
* [ ] See LATRA API status
* [ ] See successful submissions
* [ ] See failed submissions
* [ ] See pending/retrying submissions
* [ ] Inspect integration transactions

### Audit Users and Actions

* [ ] See who accessed devices
* [ ] See who interrogated devices
* [ ] See who changed parameters
* [ ] See old/new values
* [ ] See command results
* [ ] See timestamps
* [ ] See request/command IDs

### Reports

* [ ] Generate GPS report
* [ ] Generate device health report
* [ ] Generate integration report
* [ ] Generate compliance report

### Security

* [ ] LATRA cannot access internal administration
* [ ] LATRA cannot see Wialon credentials
* [ ] LATRA cannot see LATRA API credentials
* [ ] LATRA cannot access the database directly
* [ ] LATRA cannot execute arbitrary device commands
* [ ] Server-side authorization is enforced
* [ ] Sensitive operations are audited

---

# 64. Most Important Requirement

**This is NOT a new Wialon → LATRA integration project.**

The Wialon → LATRA integration already exists and must continue operating as it currently does.

The task is to add a **controlled LATRA access layer** to the existing system.

The final architecture should therefore be:

```text
                 EXISTING SYSTEM
                 
Wialon
   ↓
Real-Time Event Engine
   ↓
GPS / Events
   ↓
Middleware
   ↓
Scheduler
   ↓
LATRA API
```

with:

```text
                 NEW ACCESS LAYER

                    LATRA USER
                        ↓
             LATRA Access Module
                        ↓
              Controlled Portal API
                        ↓
              Existing System Data
```

LATRA should be able to **see and verify what is happening inside the Wialon → LATRA process and perform explicitly authorized device-management actions**, but should not receive unrestricted access to the underlying infrastructure.

The implementation must prioritize **security, traceability, auditability, real-time visibility, and reuse of the existing platform**.

