# Wallet derivation paths

The `blockchainsdk:generate-master-wallets --seed-phrase` command generates a 24-word English BIP-39 phrase and derives the first account for each selected network with an empty BIP-39 passphrase. The master and gas addresses share the derived wallet by default. A custom master address is configured separately. Customer deposit wallets generated through the drivers use independent keys.

| Network | SDK network code | Derivation path | Key and address format |
| --- | --- | --- | --- |
| Ethereum | `ethereum` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| BNB Smart Chain | `bsc` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Polygon | `polygon` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Arbitrum | `arbitrum` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Optimism | `optimism` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Base | `base` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Avalanche C-Chain | `avalanche` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Fantom | `fantom` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Cronos EVM | `cronos` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Linea | `linea` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Scroll | `scroll` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| zkSync Era | `zksync` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Celo | `celo` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Mantle | `mantle` | `m/44'/60'/0'/0/0` | secp256k1, EVM checksum address |
| Bitcoin | `bitcoin` | `m/84'/0'/0'/0/0` | secp256k1, native SegWit (Bech32 `bc1q`) |
| Litecoin | `litecoin` | `m/84'/2'/0'/0/0` | secp256k1, native SegWit (Bech32 `ltc1q`) |
| TRON | `tron` | `m/44'/195'/0'/0/0` | secp256k1, Base58Check |
| XRP Ledger | `xrp` | `m/44'/144'/0'/0/0` | secp256k1, classic Base58Check address |
| Solana | `solana` | `m/44'/501'/0'/0'` | Ed25519, Base58 public key |

A trailing apostrophe marks a hardened step. The SDK uses BIP-32 for secp256k1 networks and SLIP-0010 for Solana. All EVM networks share the same derived address for a given phrase. Their balances and transactions are separate by network.

## Recover a wallet

Import the phrase using an app that supports the path and address format in the table. Check the recovered public address against the address recorded when the wallet was generated before sending funds. Wallet apps can select different paths for the same network.

### Solana wallets generated before v1.2.2

Versions v1.2.0 and v1.2.1 derive Solana at `m/44'/501'/0'`. Starting with v1.2.2, new Solana wallets use `m/44'/501'/0'/0'`, which matched the tested Trust Wallet import. [Trust Wallet Core’s test vectors](https://github.com/trustwallet/wallet-core/blob/master/swift/Tests/Keystore/KeyStoreTests.swift#L450-L471) show examples of both paths.

Upgrading preserves configured addresses and private keys on normal generator runs. To recover an older Solana wallet, use a wallet app that supports the three-level path or import that wallet’s original private key into an app that supports its format. Keep the old credentials until any funds at the older address have been recovered or transferred. In seed mode, `--force` can replace configured gas credentials with keys derived at the new path while preserving an existing master address.
