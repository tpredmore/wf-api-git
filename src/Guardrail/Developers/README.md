# Rails Around The Rails

## Table of Contents

1. **Introduction**
2. **Understanding Guardrails**
3. **Preparing to Write a Rule**
4. **Manual Rule Creation**
5. **Testing Your Rule**
6. **Using CURL and Postman**
7. **Interpreting Responses**
8. **Future Enhancements**

---

## **1. Introduction**

Welcome to the **Guardrail Construction Service**. With great power comes great responsibility, and you are now sitting in the driver’s seat of shaping how Guardrails influence Wildfire’s decision-making processes.

Before jumping in, be aware that **Guardrails do not dictate GUI behavior.** They provide structured evaluation feedback, but it is up to the developers to implement the appropriate system response. Additionally, Guardrails are **not enabled in every area**—before spending time writing rules, check with IT to confirm the feature is available where you need it.

**For now, rule creation is done manually via SQL insert statements.** The Guardrail Construction GUI is in development and will make this process easier in the future. However, at present, you will need IT assistance to implement your rule, so come prepared with a well-thought-out request.

---

## **2. Understanding Guardrails**

Guardrails are logic-driven rules that evaluate **data** and return structured responses that systems can act upon. Each rule consists of:
- **Type** (e.g., `STATUS`, `ACTION`, `ASSIGNMENT`)
- **Area** (defines where the rule applies, e.g., `DOC_PREP`, `LENDER_SELECTION`)
- **Target** (the field being evaluated, e.g., `application.lender_selection_selected_lender`)
- **Operator** (how the target is evaluated, e.g., `exists`, `>=`, `between`)
- **Criteria** (the value or range being compared against)
- **Sub-rules** (additional conditions triggered if the base rule passes)
- **On Fail / On Pass Behavior** (`RESTRICT`, `WARN`, `LOG`, `CONTINUE`)

---

## **3. Preparing to Write a Rule**

Before you start, **write out what you are trying to enforce** in **two to three sentences**. Then, **extract key elements** for rule construction. Example:

### **Example Business Requirement**:
> "Ensure a lender is selected before proceeding in Doc Prep. Additionally, verify the contract date and first payment date meet lender guidelines."

### **Extracted Rule Elements**:
1. The lender must be selected → **Target: `application.lender_selection_selected_lender`, Operator: `exists`**
2. The first payment date must be present → **Target: `application.deal_first_payment_date`, Operator: `exists`**
3. The contract date must be present → **Target: `application.deal_contract_date`, Operator: `exists`**
4. The contract and first payment date must be within tolerance → **Operator: `date_tolerance`, Criteria: `[10,30]`**

**Before writing your first rule, get confirmation from IT to ensure Guardrails is enabled in the desired area.**

# **5. Testing Your Rule**

### **Using Postman or CURL**
#### **Example Request**
```json
{
  "application_id": 42,
  "type": "ACTION",
  "area": "TEST",
  "log_level": "sorry not in PHP",
  "testing": true,
  "datasets": {
    "test": {
      "boolean_A": true,
      "boolean_B": false,
      "field_A": "SampleValue123",
      "number_A": 150,
      "number_B": 103,
      "number_C": 700,
      "number_D": 99,
      "number_E": 100,
      "number_F": 104,
      "number_G": 102,
      "date_A": "2025-05-21",
      "date_B": "2025-06-20",
      "status_A": "ACTIVE",
      "status_B": "VALIDATED",
      "status_C": "INACTIVE",
      "allowed_statuses": "[\"ACTIVE\", \"PENDING\", \"INACTIVE\"]"
    },
    "test2": {
      "number_X": 45000,
      "id": 1234,
      "tolerance_min": 10,
      "tolerance_max": 30
    }
  }
}
```

#### **Expected Response**
```json
{
  "success": true,
  "data": {
    "success": true,
    "evaluations": [
      {
        "sequence": 1,
        "target": "[\"test.field_A\"]",
        "value": "SampleValue123",
        "operator": "exists",
        "criteria": "null",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Field A exists.",
        "fail": "Field A is missing!",
        "warn": "Ensure Field A is provided."
      },
      {
        "sequence": 2,
        "target": "[\"test.boolean_A\"]",
        "value": true,
        "operator": "is_true",
        "criteria": "null",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Boolean A is TRUE.",
        "fail": "Boolean A is FALSE!",
        "warn": "Boolean A must be TRUE before proceeding."
      },
      {
        "sequence": 3,
        "target": "[\"test.boolean_B\"]",
        "value": false,
        "operator": "is_false",
        "criteria": "null",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Boolean B is FALSE.",
        "fail": "Boolean B is TRUE!",
        "warn": "Boolean B must be FALSE before proceeding."
      },
      {
        "sequence": 4,
        "target": "[\"test.field_A\"]",
        "value": "SampleValue123",
        "operator": "regex",
        "criteria": "\"\\\\\\\"^[A-Za-z0-9]+$\\\\\\\"\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "WARN",
        "on_pass": "CONTINUE",
        "pass": "Field A format is valid.",
        "fail": "Field A format is invalid!",
        "warn": "Field A must be alphanumeric."
      },
      {
        "sequence": 5,
        "target": "[\"test.number_A\"]",
        "value": 150,
        "operator": "num_>",
        "criteria": "\"100\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Number A is greater than 100.",
        "fail": "Number A is not greater than 100!",
        "warn": "Number A must be above 100."
      },
      {
        "sequence": 6,
        "target": "[\"test.number_B\"]",
        "value": 103,
        "operator": "num_>=",
        "criteria": "\"100\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Number B is at least 100.",
        "fail": "Number B is too low!",
        "warn": "Number B must be at least 100."
      },
      {
        "sequence": 7,
        "target": "[\"test.number_C\"]",
        "value": 700,
        "operator": "num_<",
        "criteria": "\"50000\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "WARN",
        "on_pass": "CONTINUE",
        "pass": "Number C is below the limit.",
        "fail": "Number C exceeds the limit!",
        "warn": "Ensure Number C does not exceed 50,000."
      },
      {
        "sequence": 8,
        "target": "[\"test.number_D\"]",
        "value": 99,
        "operator": "num_<=",
        "criteria": "\"75000\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "WARN",
        "on_pass": "CONTINUE",
        "pass": "Number D is within the allowed range.",
        "fail": "Number D is too high!",
        "warn": "Ensure Number D is at most 75,000."
      },
      {
        "sequence": 9,
        "target": "[\"test.number_E\"]",
        "value": 100,
        "operator": "num_=",
        "criteria": "\"100\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Number E is exactly 100.",
        "fail": "Number E is not 100!",
        "warn": "Ensure Number E is exactly 100."
      },
      {
        "sequence": 10,
        "target": "[\"test.number_F\"]",
        "value": 104,
        "operator": "num_!=",
        "criteria": "\"101\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Number F is not 101.",
        "fail": "Number F is exactly 101!",
        "warn": "Ensure Number F is different from 101."
      },
      {
        "sequence": 11,
        "target": "[\"test.status_A\"]",
        "value": "ACTIVE",
        "operator": "str_=",
        "criteria": "\"ACTIVE\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Status A is ACTIVE.",
        "fail": "Status A is not ACTIVE!",
        "warn": "Ensure Status A is correctly set."
      },
      {
        "sequence": 12,
        "target": "[\"test.status_B\"]",
        "value": "VALIDATED",
        "operator": "str_!=",
        "criteria": "\"INACTIVE\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Status B is not INACTIVE.",
        "fail": "Status B is INACTIVE!",
        "warn": "Ensure Status B is not set to INACTIVE."
      },
      {
        "sequence": 13,
        "target": "[\"test.status_C\"]",
        "value": "INACTIVE",
        "operator": "in_set",
        "criteria": "\"[\\\\\\\"ACTIVE\\\\\\\",\\\\\\\"PENDING\\\\\\\", \\\\\\\"INACTIVE\\\\\\\"]\"",
        "passed": true,
        "sub_rule": null,
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Status C is in the allowed set.",
        "fail": "Status C is not in the allowed set!",
        "warn": "Ensure Status C is either ACTIVE or PENDING."
      },
      {
        "sequence": 14,
        "target": "[\"test.number_G\"]",
        "value": 102,
        "operator": "between",
        "criteria": "\"{\\\\\\\"from\\\\\\\":50,\\\\\\\"to\\\\\\\":200}\"",
        "passed": false,
        "sub_rule": null,
        "on_fail": "WARN",
        "on_pass": "CONTINUE",
        "pass": "Number G is within range.",
        "fail": "Number G is out of range!",
        "warn": "Check that Number G is between 50 and 200."
      },
      {
        "sequence": 15,
        "target": "[\"test.date_A\"]",
        "value": "2025-05-21",
        "operator": "exists",
        "criteria": "null",
        "passed": true,
        "sub_rule": {
          "passed": true,
          "criteria": "[10,30]",
          "operator_name": "date_tolerance",
          "depends": {
            "test.date_A": "2025-05-21",
            "test.date_B": "2025-06-20"
          }
        },
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Date A exists.",
        "fail": "Date A is missing!",
        "warn": "Ensure Date A is provided."
      },
      {
        "sequence": 15,
        "target": "[\"test.date_A\"]",
        "value": "2025-05-21",
        "operator": "exists",
        "criteria": "null",
        "passed": true,
        "sub_rule": {
          "passed": true,
          "criteria": "[\"test2.tolerance_max\"]",
          "operator_name": "date_tolerance",
          "depends": {
            "test.date_A": "2025-05-21",
            "test.date_B": "2025-06-20"
          }
        },
        "on_fail": "RESTRICT",
        "on_pass": "CONTINUE",
        "pass": "Date A exists.",
        "fail": "Date A is missing!",
        "warn": "Ensure Date A is provided."
      }
    ],
    "conclusion_by": "RULE_SET",
    "conclusion_notice": "No Restriction Imposed All Rules Passed"
  },
  "error": "Evaluation Complete!"
}
```

## **6. Interpreting Responses**
- **`success: true/false`** → Indicates if all rules passed
- **`conclusion_sequence`** → The sequence of the first on_fail `RESTRICT` could be a sub_rule/s that reported UP.
- **`conclusion_notice`** → Holds the pass or warn message from the rule or sub_rule that concludes the ruleset evaluation
- **`evaluations`** → Detailed breakdown of each rule evaluation

---

## **7. Future Enhancements**

The upcoming **Guardrail Construction GUI** will allow:

- **Built-in validation before applying a rule**

**Check with IT before creating your first rule!** We are here to help ensure success, **not block your progress.**

_version: 1.2_
