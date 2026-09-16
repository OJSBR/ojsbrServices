# OJSBR Services — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.1.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/ojsbrServices/releases/download/1.0.1.0/ojsbrServices-1.0.1.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that lets an editor open and follow **OJSBR
service orders** (JATS XML markup) from inside the journal. It talks only to the OJSBR connector,
it does not calculate any price, and it **never signs anything**: the OJSBR private key does not
exist in this plugin.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.1.0 |
| OJS 3.4.x   | [`stable-3_4_0`](../../tree/stable-3_4_0) | not ported yet |
| OJS 3.3.x   | [`stable-3_3_0`](../../tree/stable-3_3_0) | not ported yet |

Install into `plugins/generic/ojsbrServices`. Requires **ext-sodium** (signature verification).

## What it does

- Adds an **OJSBR Services** page to the editorial interface (`{baseUrl}/index.php/{journalPath}/ojsbr`),
  where a manager or an editor picks submissions and sends them as one markup order.
- Uploads the files of each submission to the connector, one submission at a time.
- Receives the signed callback, records the order against the submission and puts the XML and the
  galley that came back onto the current publication.
- Polls the status while the order's screen is open — never from the list.

## Trust

Every request from the connector, and every answer the plugin reads from it, carries:

```text
X-OJSBR-Timestamp: unix seconds
X-OJSBR-Signature: base64(ed25519(timestamp + "\n" + sha256_hex(body)))
```

A request is refused when the timestamp is outside ±5 minutes, when the hash of the body does not
match, or when the signature does not verify against a trusted public key — the pin shipped in
`keys/ojsbr.pub` or the one stored after a rotation, with the previous key accepted until its end
date. There is no "accept without a signature" mode, and the editor cannot paste another key or
switch the check off.

Requests to the connector carry `Authorization: Bearer {token}` and go out through
`Application::getHttpClient()`, the HTTP client the application configures — never a curl handle
of the plugin's own.

## Tests

- **PHPUnit** (`tests/`): what a signed request has to carry to be accepted (body, key, timestamp
  window), the key material the pin is read from (PEM, base64, hexadecimal, raw, placeholder), the
  proof of token, and the requests the plugin makes — JSON and multipart, with their headers —
  against a mocked HTTP client, including a connector that cannot be reached. The signature checks
  are skipped where ext-sodium is missing.
- **Cypress** (`cypress/tests/functional/`): enables the plugin, checks that the editor's screen is
  behind the login and that heartbeat, callback and key refuse a request that is not signed — with
  no signature at all, and with something that only looks like one. Each check fails with the part
  it covers removed.
- Verified on OJS 3.5.0.3.

Tests are kept in the repository and are not part of the release package.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que permite ao editor criar e acompanhar
**ordens de serviço OJSBR** (marcação XML JATS) a partir da revista. Fala **somente** com o
conector OJSBR, não calcula preço e **nunca assina**: a chave privada da OJSBR não existe neste
plugin.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).**

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.1.0 |
| OJS 3.4.x     | [`stable-3_4_0`](../../tree/stable-3_4_0) | ainda não portado |
| OJS 3.3.x     | [`stable-3_3_0`](../../tree/stable-3_3_0) | ainda não portado |

Instalar em `plugins/generic/ojsbrServices`. Requer **ext-sodium** (verificação de assinatura).

### Testes

- **PHPUnit** (`tests/`): o que um pedido assinado precisa trazer para ser aceito (corpo, chave,
  janela de tempo), o material de chave que o pin aceita (PEM, base64, hexadecimal, bruto,
  placeholder), a prova de posse do token e os pedidos que o plugin faz — JSON e multipart, com os
  cabeçalhos — contra um cliente HTTP simulado, inclusive um conector fora do ar. As verificações
  de assinatura são puladas onde falta a ext-sodium.
- **Cypress** (`cypress/tests/functional/`): liga o plugin, confere que a tela do editor está atrás
  do login e que heartbeat, callback e chave recusam pedido sem assinatura — sem nenhuma e com algo
  que só parece uma. Cada verificação reprova com a parte que ela cobre removida.
- Verificado no OJS 3.5.0.3.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Detalhes técnicos

## Settings

Editáveis pelo gestor da revista (UI do plugin):

| Setting | Uso |
|---|---|
| `ojsbrServices.connectorUrl` | Base do conector, ex. `https://ojs-plugin.ojsbr.com` |
| `ojsbrServices.token` | Token da revista (painel STNT). Bearer / `X-OJSBR-Token` |

A **pública OJSBR não é setting de UI**. Pin inicial em `keys/ojsbr.pub`. Depois da rotação (`…/ojsbr/chave`), o plugin persiste a vigente em settings internos (`ojsbrServices.trustedPublica`, `ojsbrServices.chavePublicaVersao`, `ojsbrServices.chavePublicaDtFim` + pares `*Anterior` para overlap até `dtFim`). O editor não cola outra chave nem desliga a verificação.

Referências de OS por submission ficam em `ojsbrServices.osPorSubmission` (interno).

## URL (page `ojsbr`)

`LoadHandler` registra a page `ojsbr`. `getName()` do plugin **não** entra na URL. O conector sempre monta com `index.php`:

```text
{baseUrl}/index.php/{journalPath}/ojsbr/{op}
```

| op | Handler | Auth |
|---|---|---|
| `heartbeat` | `OjsbrServiceHandler` | público, Ed25519, sem login/CSRF |
| `callback` | `OjsbrServiceHandler` | público, Ed25519, sem login/CSRF |
| `chave` | `OjsbrServiceHandler` | público, Ed25519, sem login/CSRF |
| `index` / `criar` / `status` | `OjsbrEditorHandler` | Manager / Sub-editor + CSRF |

Proibido: `/ojsbrServices/...`, `$$$call$$$` / `ROUTE_COMPONENT`, `manage&verb=` como canal STNT.

## Confiança (STNT → plugin)

Headers em **pedidos** do conector ao plugin e em **respostas** de `GET/POST /plugin/v1/...`:

```text
X-OJSBR-Timestamp: unix seconds
X-OJSBR-Signature: base64(ed25519(timestamp + "\n" + sha256_hex(body)))
```

`sha256_hex` = `hash('sha256', $body)` (hex minúsculo). Recusar se timestamp fora de ±5 min, hash do body não bater ou a assinatura não validar na pública confiável (pin ou persistida; overlap com a anterior até `dtFim`). Sem modo “aceitar sem assinatura”.

O plugin **só verifica**. A privada fica no `stnt-ojs`.

### Heartbeat

Pedido assinado `{ "ts", "nonce" }`. Resposta **200 sem Ed25519**:

```json
{
  "ok": true,
  "chavePublicaVersao": "pin",
  "journalPath": "minha-revista",
  "hmac": "base64(HMAC-SHA256(pluginToken, nonce))"
}
```

### Callback

Pedido assinado com status, `artefatos[]` (`contentBase64`) e metadados. Persiste a referência da OS e tenta aplicar XML/galley na publication corrente (`OjsbrGalleyApplier`).

### Chave

Pedido assinado `{ versao, publica, dtFim }`. Persiste a pública e devolve `{ versao, hmac }` (HMAC do token com `nonce` do body ou, se ausente, com `versao`). Sem Ed25519 na resposta.

## Plugin → conector

`Authorization: Bearer {token}` (e `X-OJSBR-Token`). O plugin **verifica** a assinatura da resposta.

```text
POST {connectorUrl}/plugin/v1/ordens
GET  {connectorUrl}/plugin/v1/ordens/:numero
POST {connectorUrl}/plugin/v1/ordens/:numero/itens/:submissionId/arquivos
```

Create JSON (sem preço, sem bytes):

```json
{
  "service": "OS_JATS_XML",
  "ojsVersion": "3.5",
  "journalPath": "minha-revista",
  "journal": {
    "title": "",
    "acronym": "",
    "issnPrint": "",
    "issnOnline": "",
    "publisher": "",
    "locales": ["pt_BR", "en"],
    "metadata": {}
  },
  "items": [
    {
      "submissionId": "123",
      "publicationId": "456",
      "title": "",
      "doi": "",
      "locale": "pt_BR",
      "metadata": {},
      "galleys": [
        { "id": "10", "label": "PDF", "locale": "pt_BR", "genre": "galley", "fileName": "artigo.pdf" }
      ],
      "files": [
        { "role": "pdf_final", "fileName": "artigo.pdf", "locale": "pt_BR" },
        { "role": "manuscrito", "fileName": "artigo.docx" },
        { "role": "galley", "galleyId": "10", "fileName": "artigo.pdf", "locale": "pt_BR" }
      ]
    }
  ]
}
```

Depois do `numero`, sobe arquivos **em série** (um submission por vez), multipart com `role` (`pdf_final` | `manuscrito` | `galley`) e `galleyId` quando couber. Timeout 120 s, corpo 32 MiB.

Se a OS nascer bloqueada por crédito: a UI mostra `creditoFaltante` e o texto de regularização da OJSBR — sem boleto/PIX.

UI do editor: `{baseUrl}/index.php/{journalPath}/ojsbr` (também no atalho das ações do plugin).
