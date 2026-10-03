# Legacy API Client

<cite>
**Referenced Files in This Document**
- [LegacyApiClient.php](file://includes/RouterOS/LegacyApiClient.php)
- [RouterClientInterface.php](file://includes/RouterOS/RouterClientInterface.php)
- [RestClient.php](file://includes/RouterOS/RestClient.php)
- [RouterFactory.php](file://includes/RouterOS/RouterFactory.php)
</cite>

## Update Summary
**Changes Made**
- Added documentation for new file operation methods (uploadHotspotStub, listFiles) implemented in LegacyApiClient
- Updated binary protocol communication section to include arbitrary byte transmission capabilities
- Enhanced file management capabilities documentation with examples of hotspot stub file operations
- Updated troubleshooting guide to include file operation issues specific to legacy protocol

## Table of Contents
1. [Introduction](#introduction)
2. [Project Structure](#project-structure)
3. [Core Components](#core-components)
4. [Architecture Overview](#architecture-overview)
5. [Detailed Component Analysis](#detailed-component-analysis)
6. [File Operations and Binary Protocol](#file-operations-and-binary-protocol)
7. [Dependency Analysis](#dependency-analysis)
8. [Performance Considerations](#performance-considerations)
9. [Troubleshooting Guide](#troubleshooting-guide)
10. [Conclusion](#conclusion)
11. [Appendices](#appendices)

## Introduction
This document explains the Legacy API client implementation that communicates with MikroTik RouterOS using the legacy binary protocol over TCP port 8728 or TLS port 8729. It covers how `LegacyApiClient` implements the unified `RouterClientInterface`, the binary sentence protocol, connection and authentication flows, data serialization and deserialization, and how it compares to the REST client. It also provides operational examples, guidance on when to choose the legacy protocol, and troubleshooting for common issues such as binary format problems, authentication failures, version compatibility, and file operation challenges.

## Project Structure
The RouterOS integration is implemented as a small set of PHP classes under `includes/RouterOS`:
- `RouterClientInterface.php` defines the unified contract used by both clients.
- `RestClient.php` implements the same contract over RouterOS v7 REST/HTTPS.
- `LegacyApiClient.php` implements the same contract over the legacy binary protocol.
- `RouterFactory.php` selects and constructs the correct client based on router configuration.

```mermaid
graph TB
subgraph "RouterOS Integration"
IF["RouterClientInterface"]
LC["LegacyApiClient"]
RC["RestClient"]
RF["RouterFactory"]
end
IF --> LC
IF --> RC
RF --> LC
RF --> RC
```

**Diagram sources**
- [RouterClientInterface.php:31-151](file://includes/RouterOS/RouterClientInterface.php#L31-L151)
- [LegacyApiClient.php:22-692](file://includes/RouterOS/LegacyApiClient.php#L22-L692)
- [RestClient.php:24-520](file://includes/RouterOS/RestClient.php#L24-L520)
- [RouterFactory.php:29-55](file://includes/RouterOS/RouterFactory.php#L29-L55)

**Section sources**
- [RouterClientInterface.php:1-151](file://includes/RouterOS/RouterClientInterface.php#L1-L151)
- [LegacyApiClient.php:1-692](file://includes/RouterOS/LegacyApiClient.php#L1-L692)
- [RestClient.php:1-520](file://includes/RouterOS/RestClient.php#L1-L520)
- [RouterFactory.php:1-56](file://includes/RouterOS/RouterFactory.php#L1-L56)

## Core Components
- `RouterClientInterface`: Defines the shared operations for identity, resource info, hotspot users/profiles, active sessions, interfaces, file operations, and connection testing. Both REST and legacy clients implement this interface so higher layers can remain transport-agnostic.
- `LegacyApiClient`: Implements the legacy binary protocol, including length-prefixed word encoding, sentence I/O, dual-mode login (plaintext and challenge-response), command execution, file operations, and normalization helpers.
- `RestClient`: Implements the same interface over HTTPS JSON REST endpoints.
- `RouterFactory`: Builds the appropriate client from router configuration, decrypting credentials in memory only.

Key responsibilities:
- Binary protocol framing and parsing
- Connection establishment and optional TLS
- Authentication handshake
- Command execution and record collection
- File operations with native binary transmission
- Data normalization to match the interface contract

**Section sources**
- [RouterClientInterface.php:31-151](file://includes/RouterOS/RouterClientInterface.php#L31-L151)
- [LegacyApiClient.php:22-692](file://includes/RouterOS/LegacyApiClient.php#L22-L692)
- [RestClient.php:24-520](file://includes/RouterOS/RestClient.php#L24-L520)
- [RouterFactory.php:29-55](file://includes/RouterOS/RouterFactory.php#L29-L55)

## Architecture Overview
The legacy client connects directly to RouterOS via raw TCP or TLS, sending and receiving "sentences" composed of length-prefixed words. The first word is a tag (`!re`, `!done`, `!trap`, `!fatal`), followed by attribute words like `=key=value`. Commands are sent as sentences; replies stream multiple `!re` records before a final `!done`.

```mermaid
sequenceDiagram
participant App as "Application"
participant Factory as "RouterFactory"
participant Client as "LegacyApiClient"
participant Router as "RouterOS Binary API"
App->>Factory : aircoins_router_client(routerConfig)
Factory-->>App : LegacyApiClient instance
App->>Client : __construct(config)
Client->>Router : connect() + login()
App->>Client : testConnection()
Client->>Router : /system/identity/print
Router-->>Client : !re + !done
Client-->>App : {ok,name,version,board-name}
```

**Diagram sources**
- [RouterFactory.php:29-55](file://includes/RouterOS/RouterFactory.php#L29-L55)
- [LegacyApiClient.php:40-50](file://includes/RouterOS/LegacyApiClient.php#L40-L50)
- [LegacyApiClient.php:70-94](file://includes/RouterOS/LegacyApiClient.php#L70-L94)
- [LegacyApiClient.php:101-167](file://includes/RouterOS/LegacyApiClient.php#L101-L167)
- [LegacyApiClient.php:430-440](file://includes/RouterOS/LegacyApiClient.php#L430-L440)

## Detailed Component Analysis

### LegacyApiClient: Binary Protocol and Operations
`LegacyApiClient` implements the full lifecycle for legacy protocol communication:
- Connection: Opens a TCP socket on port 8728 or TLS on 8729, with configurable peer verification.
- Authentication: Attempts plaintext login first; if the router responds with a challenge, computes MD5-based response.
- Serialization: Encodes lengths per the RouterOS spec and writes length-prefixed words terminated by a zero-length word.
- Deserialization: Reads length-prefixed words until the terminator, parses attributes into associative arrays.
- Command execution: Sends commands with attributes and queries, collects `!re` records, and throws on `!trap` or `!fatal`.
- Interface methods: Provide normalized results for identity, resources, hotspot users/profiles, active sessions, interfaces, file operations, and MAC lookup.

```mermaid
classDiagram
class RouterClient {
<<interface>>
+testConnection() array
+identity() array
+resource() array
+hotspotUsers() array
+addHotspotUser(name, pass, profile, comment, uptimeLimit) array
+deleteHotspotUser(id) bool
+hotspotProfiles() array
+addHotspotProfile(attrs) array
+deleteHotspotProfile(id) bool
+activeSessions() array
+kickSession(id) bool
+interfaces() array
+findActiveByMac(mac) array|null
+uploadHotspotStub(path, content) bool
+listFiles(dir) array
}
class LegacyApiClient {
-sock resource
-host string
-port int
-username string
-password string
-tlsVerify bool
+__construct(router)
+close() void
+encodeLength(len) string
+decodeLength(sock) int
+writeSentence(words) void
+readSentence() array
+parseSentence(words) array
+cmd(command, attrs, queries) array
+testConnection() array
+identity() array
+resource() array
+hotspotUsers() array
+addHotspotUser(...) array
+deleteHotspotUser(id) bool
+hotspotProfiles() array
+addHotspotProfile(attrs) array
+deleteHotspotProfile(id) bool
+activeSessions() array
+kickSession(id) bool
+interfaces() array
+findActiveByMac(mac) array|null
+uploadHotspotStub(path, content) bool
+listFiles(dir) array
-connect() void
-login() void
-exec(command, attrs, queries) array
-mapSession(record) array
-toBool(value) bool
-parseUptime(raw) int
}
RouterClient <|.. LegacyApiClient
```

**Diagram sources**
- [RouterClientInterface.php:31-151](file://includes/RouterOS/RouterClientInterface.php#L31-L151)
- [LegacyApiClient.php:22-692](file://includes/RouterOS/LegacyApiClient.php#L22-L692)

#### Binary Length Encoding and Decoding
The legacy protocol uses variable-length encodings for word sizes:
- 1 byte for short lengths
- 2 bytes with high bit set
- 3 bytes with specific prefix bits
- 4 bytes with another prefix
- 5 bytes for large payloads

These functions are public static so they can be unit-tested without network access.

```mermaid
flowchart TD
Start(["encodeLength(len)"]) --> CheckNeg{"len < 0?"}
CheckNeg --> |Yes| ThrowNeg["Throw RuntimeException"]
CheckNeg --> |No| Range1{"len < 0x80?"}
Range1 --> |Yes| OneByte["Return 1-byte prefix"]
Range1 --> |No| Range2{"len < 0x4000?"}
Range2 --> |Yes| TwoBytes["Return 2-byte prefix"]
Range2 --> |No| Range3{"len < 0x200000?"}
Range3 --> |Yes| ThreeBytes["Return 3-byte prefix"]
Range3 --> |No| Range4{"len < 0x10000000?"}
Range4 --> |Yes| FourBytes["Return 4-byte prefix"]
Range4 --> |No| FiveBytes["Return 5-byte prefix"]
```

**Diagram sources**
- [LegacyApiClient.php:186-204](file://includes/RouterOS/LegacyApiClient.php#L186-L204)

#### Authentication Flow
Authentication supports two modes:
- Plaintext login for newer firmware (>= 6.43).
- Challenge-response for older firmware, where the server returns a hex-encoded challenge and the client must respond with `00` concatenated with MD5 of null byte, password, and challenge bytes.

```mermaid
sequenceDiagram
participant Client as "LegacyApiClient"
participant Router as "RouterOS"
Client->>Router : "/login =name=... =password=..."
alt Plaintext accepted
Router-->>Client : "!done"
Client-->>Client : Login success
else Challenge returned
Router-->>Client : "!re ret=<hex>"
Router-->>Client : "!trap"
Client->>Client : Compute response = "00" . md5(0x00 . password . challenge)
Client->>Router : "/login =name=... =response=..."
Router-->>Client : "!done"
Client-->>Client : Login success
end
```

**Diagram sources**
- [LegacyApiClient.php:101-167](file://includes/RouterOS/LegacyApiClient.php#L101-L167)

#### Command Execution and Record Collection
Commands are executed by writing a sentence containing the command path, attributes, and query filters. Replies are read iteratively:
- `!re` records are collected into an array.
- `!done` terminates the loop and returns metadata.
- `!trap` and `!fatal` raise exceptions.

```mermaid
flowchart TD
Start(["exec(command, attrs, queries)"]) --> BuildWords["Build words: command + attributes + queries"]
BuildWords --> WriteSentence["writeSentence(words)"]
WriteSentence --> Loop["Read sentence loop"]
Loop --> ReadTag["Read tag and attributes"]
ReadTag --> TagCheck{"Tag type?"}
TagCheck --> |!re| Collect["Collect record"]
Collect --> Loop
TagCheck --> |!done| ReturnDone["Return records + done"]
TagCheck --> |!trap| ThrowTrap["Throw RuntimeException"]
TagCheck --> |!fatal| ThrowFatal["Throw RuntimeException"]
```

**Diagram sources**
- [LegacyApiClient.php:386-423](file://includes/RouterOS/LegacyApiClient.php#L386-L423)

#### Data Normalization Helpers
- `mapSession`: Maps raw active session records to a consistent shape across implementations.
- `toBool`: Coerces RouterOS boolean-like strings to PHP booleans.
- `parseUptime`: Parses integer seconds or duration strings like `"6w5d4h3m2s"` into whole seconds.

**Section sources**
- [LegacyApiClient.php:611-665](file://includes/RouterOS/LegacyApiClient.php#L611-L665)

### RouterClientInterface: Unified Contract
The interface standardizes return shapes for identity, resource metrics, hotspot user management, profiles, active sessions, interfaces, file operations, and connection testing. This allows application code to switch between REST and legacy clients transparently.

Key contracts include:
- `testConnection()` returns connectivity and basic device info.
- `hotspotUsers()` and `hotspotProfiles()` return normalized lists.
- `activeSessions()` and `findActiveByMac()` provide session visibility.
- `interfaces()` returns interface stats.
- `uploadHotspotStub()` enables file upload with automatic encoding.
- `listFiles()` provides directory listing functionality.

**Section sources**
- [RouterClientInterface.php:31-151](file://includes/RouterOS/RouterClientInterface.php#L31-L151)

### RestClient: REST Comparison
The REST client uses HTTPS JSON endpoints and maps HTTP verbs to RouterOS operations:
- GET -> print
- PUT -> add
- PATCH -> set
- DELETE -> remove
- POST -> command

It handles JSON decoding, numeric casting, and `.id` handling. While more modern and easier to integrate with web stacks, it may have different performance characteristics compared to the binary protocol.

**Section sources**
- [RestClient.php:1-520](file://includes/RouterOS/RestClient.php#L1-L520)

### RouterFactory: Client Selection
The factory resolves the plaintext password from encrypted storage and chooses the client based on `api_type`:
- `'rest'` -> `RestClient`
- anything else -> `LegacyApiClient`

This abstraction keeps router configuration simple and centralizes credential decryption.

**Section sources**
- [RouterFactory.php:29-55](file://includes/RouterOS/RouterFactory.php#L29-L55)

## File Operations and Binary Protocol

### Native Binary Transmission Capabilities
The Legacy API client leverages the native binary protocol's ability to transmit arbitrary byte sequences without encoding overhead. Unlike the REST client which requires base64 encoding for binary data, the legacy client sends raw bytes directly through the length-prefixed word encoding system.

```mermaid
flowchart TD
A["Raw File Content"] --> B["Legacy API: Direct Binary Transmission"]
C["Raw File Content"] --> D["REST API: Base64 Encoding"]
B --> E["Length-Prefixed Words"]
D --> F["JSON Payload"]
E --> G["RouterOS Binary API"]
F --> H["HTTP/JSON Endpoint"]
G --> I["Native Binary Processing"]
H --> J["JSON Decoding + Base64 Decode"]
```

**Diagram sources**
- [LegacyApiClient.php:668-675](file://includes/RouterOS/LegacyApiClient.php#L668-L675)
- [RestClient.php:460-475](file://includes/RouterOS/RestClient.php#L460-L475)

### File Upload Implementation
The `uploadHotspotStub()` method demonstrates the efficiency difference between protocols:

**Legacy API Approach:**
- Uses `/file` command with direct binary content transmission
- No encoding overhead - raw bytes sent through binary protocol
- Leverages existing length-prefix encoding for arbitrary data

**REST API Approach:**
- Requires base64 encoding of file content
- Uses PUT/PATCH operations on `/rest/file` endpoint
- Additional processing overhead for encoding/decoding

### File Listing Functionality
Both clients implement `listFiles()` but with different approaches:

**Legacy API:**
- Uses `/file/print` with name filter query
- Supports regex patterns via `~` prefix
- Returns structured file information

**REST API:**
- Fetches all files and performs client-side filtering
- Limited by RouterOS REST API query capabilities
- More flexible but potentially less efficient

**Section sources**
- [LegacyApiClient.php:668-690](file://includes/RouterOS/LegacyApiClient.php#L668-L690)
- [RestClient.php:460-518](file://includes/RouterOS/RestClient.php#L460-L518)

## Dependency Analysis
The legacy client depends on:
- `RouterClientInterface` for method signatures and return contracts.
- PHP stream sockets for TCP/TLS communication.
- RouterOS binary protocol semantics for framing and tags.

```mermaid
graph LR
IF["RouterClientInterface"] --> LC["LegacyApiClient"]
LC --> Sockets["PHP Streams (TCP/TLS)"]
LC --> RouterOS["RouterOS Binary API"]
```

**Diagram sources**
- [RouterClientInterface.php:31-151](file://includes/RouterOS/RouterClientInterface.php#L31-L151)
- [LegacyApiClient.php:70-94](file://includes/RouterOS/LegacyApiClient.php#L70-L94)

**Section sources**
- [LegacyApiClient.php:20-22](file://includes/RouterOS/LegacyApiClient.php#L20-L22)
- [LegacyApiClient.php:70-94](file://includes/RouterOS/LegacyApiClient.php#L70-L94)

## Performance Considerations
- Binary protocol overhead: The legacy protocol sends compact length-prefixed words, which can be more efficient than JSON payloads for bulk operations.
- Connection reuse: Each `LegacyApiClient` instance maintains a single socket handle. Reuse instances to avoid repeated handshakes.
- Keep-alive: The client does not implement explicit keep-alive logic beyond keeping the socket open. Ensure long-lived connections are managed at the application layer.
- TLS overhead: Using port 8729 adds TLS encryption costs; consider whether security requirements justify the overhead versus plain TCP 8728.
- Batch operations: Group related commands where possible to reduce round trips.
- File operations: Legacy API provides superior performance for file uploads due to native binary transmission without encoding overhead.

## Troubleshooting Guide

### Binary Format Problems
Symptoms:
- Short reads or timeouts during sentence parsing.
- Malformed length prefixes causing decode errors.

Checks:
- Verify `encodeLength` and `decodeLength` behavior with known inputs.
- Ensure no partial writes occur; use `writeAll` semantics.
- Confirm RouterOS firmware supports the expected binary protocol version.

Relevant code paths:
- [LegacyApiClient.php:186-241](file://includes/RouterOS/LegacyApiClient.php#L186-L241)
- [LegacyApiClient.php:255-331](file://includes/RouterOS/LegacyApiClient.php#L255-L331)

### Authentication Failures
Symptoms:
- Plaintext login rejected with `!trap`.
- Challenge-response fails due to incorrect MD5 computation.

Checks:
- Confirm RouterOS version compatibility (challenge-response for pre-6.43).
- Validate username/password and ensure no extra whitespace.
- Inspect `ret` challenge value and verify MD5 input includes null byte.

Relevant code paths:
- [LegacyApiClient.php:101-167](file://includes/RouterOS/LegacyApiClient.php#L101-L167)

### Compatibility with Different RouterOS Versions
- Newer firmware accepts plaintext login; older firmware requires challenge-response.
- Some fields may vary between versions; normalize values using helpers like `toBool` and `parseUptime`.

Relevant code paths:
- [LegacyApiClient.php:630-665](file://includes/RouterOS/LegacyApiClient.php#L630-L665)

### Connection Issues
Symptoms:
- Connect timeout or refusal.
- TLS handshake failure.

Checks:
- Verify host/port (8728 for TCP, 8729 for TLS).
- Ensure firewall rules allow traffic.
- For TLS, check certificate validation settings (`tls_verify`).

Relevant code paths:
- [LegacyApiClient.php:70-94](file://includes/RouterOS/LegacyApiClient.php#L70-L94)

### File Operation Issues
Symptoms:
- File upload failures with binary content.
- File listing returns incomplete results.
- Permission denied errors during file operations.

Checks:
- Verify file path permissions on RouterOS filesystem.
- Ensure sufficient disk space on target partition.
- Check file size limits imposed by RouterOS.
- Validate binary content integrity before upload.

Relevant code paths:
- [LegacyApiClient.php:668-690](file://includes/RouterOS/LegacyApiClient.php#L668-L690)
- [RestClient.php:460-518](file://includes/RouterOS/RestClient.php#L460-L518)

**Section sources**
- [LegacyApiClient.php:70-94](file://includes/RouterOS/LegacyApiClient.php#L70-L94)
- [LegacyApiClient.php:101-167](file://includes/RouterOS/LegacyApiClient.php#L101-L167)
- [LegacyApiClient.php:186-241](file://includes/RouterOS/LegacyApiClient.php#L186-L241)
- [LegacyApiClient.php:255-331](file://includes/RouterOS/LegacyApiClient.php#L255-L331)
- [LegacyApiClient.php:630-665](file://includes/RouterOS/LegacyApiClient.php#L630-L665)
- [LegacyApiClient.php:668-690](file://includes/RouterOS/LegacyApiClient.php#L668-L690)

## Conclusion
The Legacy API client provides a robust, low-level interface to RouterOS using the binary protocol. It offers fine-grained control over framing, authentication, and command execution, making it suitable for environments where performance and compatibility with older RouterOS versions matter. The unified `RouterClientInterface` ensures that application code remains portable across REST and legacy transports. Choose the legacy protocol when you need maximum efficiency, broad RouterOS version support, direct access to features not exposed via REST, or optimal file operation performance through native binary transmission. Use REST when you prefer HTTP/JSON semantics, simpler integration with web stacks, or when targeting modern RouterOS deployments.

## Appendices

### RouterOS Operations Using the Legacy Protocol
Examples of typical operations:
- List hotspot users: `/ip/hotspot/user/print`
- Add hotspot user: `/ip/hotspot/user/add` with attributes `name`, `password`, `profile`, optional `comment`, `limit-uptime`
- Remove hotspot user: `/ip/hotspot/user/remove` with `.id`
- List hotspot profiles: `/ip/hotspot/user/profile/print`
- Add hotspot profile: `/ip/hotspot/user/profile/add` with `name` and optional attributes
- Remove hotspot profile: `/ip/hotspot/user/profile/remove` with `.id`
- List active sessions: `/ip/hotspot/active/print`
- Kick session: `/ip/hotspot/active/remove` with `.id`
- List interfaces: `/interface/print`
- Find active session by MAC: `/ip/hotspot/active/print` with query `mac=<value>`
- Upload hotspot stub: `/file` with `name` and `contents` attributes
- List files: `/file/print` with name filter query

These operations map to methods like `hotspotUsers()`, `addHotspotUser()`, `deleteHotspotUser()`, `hotspotProfiles()`, `addHotspotProfile()`, `deleteHotspotProfile()`, `activeSessions()`, `kickSession()`, `interfaces()`, `findActiveByMac()`, `uploadHotspotStub()`, and `listFiles()`.

**Updated** The attribute name has been corrected from `uptime-limit` to `limit-uptime` for hotspot user operations, and the endpoint path has been updated from `/ip/hotspot/profile` to `/ip/hotspot/user/profile` for hotspot profile operations to maintain RouterOS API compatibility. Additionally, file operation methods have been added to support hotspot stub file management.

**Section sources**
- [LegacyApiClient.php:466-690](file://includes/RouterOS/LegacyApiClient.php#L466-L690)

### When to Choose Legacy Over REST
Choose legacy when:
- You need to support older RouterOS versions that do not expose all features via REST.
- You require lower overhead and faster throughput for frequent small operations.
- You need direct access to RouterOS commands not available through REST endpoints.
- You require optimal file operation performance through native binary transmission.
- You need to handle large binary files efficiently without encoding overhead.

Choose REST when:
- You are targeting modern RouterOS deployments with stable REST APIs.
- You prefer HTTP/JSON semantics and easier integration with web frameworks.
- You want standardized error responses and well-defined HTTP status codes.
- You need broader ecosystem support and tooling compatibility.

**Section sources**
- [RestClient.php:1-18](file://includes/RouterOS/RestClient.php#L1-L18)
- [RouterFactory.php:48-55](file://includes/RouterOS/RouterFactory.php#L48-L55)

### RouterOS API Compatibility Notes
**Important**: Recent RouterOS API compatibility fixes have been applied to ensure proper operation:

1. **Attribute Name Correction**: The attribute name for hotspot user time limits has been corrected from `uptime-limit` to `limit-uptime` to match RouterOS API specifications.

2. **Endpoint Path Update**: Hotspot profile operations now use the correct endpoint path `/ip/hotspot/user/profile` instead of `/ip/hotspot/profile` to align with RouterOS API structure.

3. **File Operation Enhancement**: New file operation methods provide consistent API across both connection types, with legacy protocol supporting native binary transmission for optimal performance.

These changes ensure compatibility with various RouterOS versions and prevent API call failures due to incorrect attribute names or endpoint paths.

**Section sources**
- [LegacyApiClient.php:476](file://includes/RouterOS/LegacyApiClient.php#L476)
- [LegacyApiClient.php:491](file://includes/RouterOS/LegacyApiClient.php#L491)
- [LegacyApiClient.php:514](file://includes/RouterOS/LegacyApiClient.php#L514)
- [LegacyApiClient.php:540](file://includes/RouterOS/LegacyApiClient.php#L540)
- [LegacyApiClient.php:668-690](file://includes/RouterOS/LegacyApiClient.php#L668-L690)
- [RestClient.php:99](file://includes/RouterOS/RestClient.php#L99)
- [RestClient.php:114](file://includes/RouterOS/RestClient.php#L114)
- [RestClient.php:137](file://includes/RouterOS/RestClient.php#L137)
- [RestClient.php:163](file://includes/RouterOS/RestClient.php#L163)
- [RestClient.php:460-518](file://includes/RouterOS/RestClient.php#L460-L518)