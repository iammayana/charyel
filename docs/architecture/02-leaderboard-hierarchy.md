# Architecture Decision Record: Regional & Church Leaderboard Hierarchy

## 1. Scope & Locality Hierarchy
To foster genuine community connection without invasive geolocation tracking, Charyel structures leaderboards hierarchically:

```text
Global
 └── Country (e.g. NG, US, GH, KE)
      └── State / Region (e.g. Lagos, Federal Capital Territory)
           └── District / Locality (e.g. Ikeja, Surulere)
                └── Church / Assembly League (Self-selected fellowship)
```

## 2. Privacy-by-Design Principles
1. **No Precise GPS:** Users self-select their church fellowship and locality from standard administrative boundaries. No fine-grained coordinates are stored.
2. **Pseudonymous Play:** Users can opt to display custom pilgrim display names and generated avatars rather than personal identity details.
3. **Partitioned Aggregation:** Leaderboard standings are materialized asynchronously into roll-up tables via scheduled Laravel jobs, ensuring high-throughput reads without contention on transaction logs.
