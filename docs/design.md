# Laboratory 4 Section A Design Deliverables

## ERD

```mermaid
erDiagram
  USERS ||--o{ BORROWING_REQUESTS : submits
  USERS ||--o{ AUDIT_LOGS : creates
  USERS ||--o{ MAINTENANCE_REQUESTS : submits
  EQUIPMENT ||--o{ BORROWING_REQUESTS : requested_for
  EQUIPMENT ||--o{ MAINTENANCE_REQUESTS : has
  USERS ||--o{ BORROWING_REQUESTS : approves
  USERS { string id PK string name string role }
  EQUIPMENT { string id PK string code string name string status string condition }
  BORROWING_REQUESTS { string id PK string equipment_id FK string requester_id FK string approved_by FK string status date requested_on }
  MAINTENANCE_REQUESTS { string id PK string equipment_id FK string requester_id FK string status string description }
  AUDIT_LOGS { string id PK string user_id FK string action string module string record_id string description timestamp created_at }
```

## Use Case Diagram

```mermaid
flowchart LR
  A[Administrator] --> U[Manage users and equipment]
  A --> R[Approve or reject requests]
  A --> M[Manage maintenance]
  A --> L[View audit logs and reports]
  S[Laboratory Staff] --> V[View equipment]
  S --> B[Create borrowing transactions]
  S --> T[Process returns]
  S --> Q[Submit maintenance requests]
  X[Requester / Viewer] --> V
  X --> N[Submit borrowing requests]
  X --> H[View own history]
```

## Permission Matrix

| Function | Administrator | Laboratory Staff | Requester / Viewer |
| --- | --- | --- | --- |
| View equipment | Yes | Yes | Yes |
| Submit borrowing request | Yes | Yes | Yes |
| Approve/reject request | Yes | No | No |
| Release/return equipment | Yes | Yes | No |
| Submit maintenance request | Yes | Yes | No |
| Manage users/equipment | Yes | No | No |
| View audit logs | Yes | No | No |
| View own history | Yes | Yes | Yes |

## Approval Workflow

```mermaid
flowchart TD
  A[Submitted] --> P[Pending]
  P -->|Administrator approves| AP[Approved]
  P -->|Administrator rejects| RJ[Rejected]
  AP --> RL[Released]
  RL --> RT[Returned]
  RL --> OD[Overdue]
  RT --> CL[Closed]
```

## Business Rules

BR-A4-01 only available equipment may be requested. BR-A4-02 staff cannot approve their own request. BR-A4-03 only administrators approve or reject. BR-A4-04 only approved requests release. BR-A4-05 release marks equipment borrowed. BR-A4-06 return marks equipment available unless damaged. BR-A4-07 rejected requests cannot release. BR-A4-08 returned transactions cannot be processed twice. BR-A4-09 maintenance equipment cannot be borrowed. BR-A4-10 sensitive operations create audit entries.

## Functional Test Results

Run `php -l index.php` and `php -l lib/AssetSystem.php` before starting the server. Test the workflow manually through the PHP interface using the Administrator, Laboratory Staff, and Requester / Viewer accounts. The Audit Logs page provides the screenshot target after approving a request as Maria Santos.