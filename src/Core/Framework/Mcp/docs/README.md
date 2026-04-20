# MCP Server Documentation

The Shopware MCP server lets AI clients (Claude Desktop, Cursor, etc.) interact with a Shopware shop through the Model Context Protocol.

**Planning:** [product-epic-backlog.md](product-epic-backlog.md) is the planning source of truth. The current V1 direction is:

- slim core with MCP foundation and DAL-style primitives under `shopware-*`
- merchant workflow tools move into **`SwagMcpMerchantAssistant`** with `merchant-*`
- tool visibility is controlled per integration in Admin, with **no tools selected by default**
- official documentation moves to **developer.shopware.com/docs**
- local developer MCP stays with [ai-coding-tools](https://github.com/shopwareLabs/ai-coding-tools), not `/api/_mcp`

These in-repo docs are transitional planning material until the official docs are published.

## Getting started

| # | Doc | What you learn |
|---|-----|----------------|
| 1 | [Setup](setup.md) | Prerequisites, installation, feature flag, connecting your AI client |
| 2 | [Tools](tools.md) | All available tools, their parameters, and example calls |
| 3 | [Examples](examples.md) | Step-by-step workflows: searching data, creating products, processing orders |

## Going deeper

| Doc | What you learn |
|-----|----------------|
| [Security](security.md) | Authentication, ACL, tool allowlists, telemetry/audit trail, app HMAC signing |
| [Spec Coverage](spec-coverage.md) | Which MCP server features exist in the spec, what Shopware supports today, and what still needs alignment |
| [Extensibility](extensibility.md) | Adding custom tools via plugins and apps |
| [Best Practices](best-practices.md) | Design principles for building MCP tools and prompts |
| [Agent User Stories](agent-user-stories.md) | What agents can (and can't yet) do, tracked by status |

## IDE-specific

| Doc | What you learn |
|-----|----------------|
| [Cursor Rule](cursor-rule.md) | Speed up Cursor by caching tool schemas in a `.cursor/rules/` file |
