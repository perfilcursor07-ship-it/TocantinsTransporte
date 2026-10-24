# Espaço publicitário do WiFi Tocantins — guia para parceiros

**Para:** empresas que querem anunciar para os passageiros
**Versão:** 1.0

---

## O que é

O WiFi Tocantins opera internet via Starlink em ônibus. Antes de pagar pelo acesso,
o passageiro vê uma oferta:

> **Tenha agora R$ 1,00 de desconto** — veja o conteúdo do nosso parceiro

Quem aceita vê o conteúdo do anunciante e ganha desconto no plano. Quem recusa
paga o preço cheio. **A exposição é voluntária e o desconto sai do nosso bolso** —
o passageiro tem motivo real para prestar atenção.

### Números

| Item | Valor |
|---|---|
| Exposição | 1 por passageiro, antes do pagamento |
| Tempo mínimo garantido | configurável (padrão 20s) |
| Público | passageiros de ônibus intermunicipais, Tocantins e região |

---

## Três formas de integrar

Escolha a que couber. **O Nível 1 não exige nada de você além do arquivo.**

| Nível | Formato | O que você entrega | Esforço técnico |
|---|---|---|---|
| 1 | Vídeo | um arquivo MP4 | nenhum |
| 2 | Página | uma URL + 1 ajuste de header | baixo |
| 3 | API | endpoints conforme spec abaixo | médio |

---

## Nível 1 — Vídeo

O mais simples. Você entrega o arquivo, a gente hospeda e exibe no nosso player.

**Requisitos do arquivo**

- Formato: MP4 (H.264 + AAC)
- Resolução: 720x1280 (vertical) ou 1280x720
- Duração: 15 a 60 segundos
- Tamanho: até 10 MB
- Sem áudio essencial — muita gente assiste sem som. Use legenda queimada.

**Como funciona**

O passageiro assiste no player do portal. O desconto é liberado quando o vídeo
**termina**. Como o player é nosso, esse é o único nível em que conseguimos
garantir de fato que o conteúdo foi exibido até o fim.

**O que você recebe:** relatório de exibições completas, por período.

---

## Nível 2 — Página embutida (iframe)

Sua página abre dentro de um modal no portal, sem tirar o passageiro de lá.

**O que você entrega**

1. Uma URL pública, responsiva, que funcione em 360px de largura.
2. **Permissão de embed.** Seu servidor precisa autorizar nosso domínio:

```
Content-Security-Policy: frame-ancestors https://tocantinstransportewifi.com.br
```

E **não** enviar `X-Frame-Options: DENY` ou `SAMEORIGIN` para a nossa origem.
Esse header é legado, não aceita allowlist por origem, e em vários navegadores
ainda prevalece sobre o CSP.

> ⚠️ Sem esse ajuste o modal abre **em branco**. Não é algo que a gente consiga
> contornar — quem bloqueia é o navegador, obedecendo ao seu servidor.

3. Se a página usar sessão/login, os cookies precisam de `SameSite=None; Secure`,
   senão a sessão não sobrevive ao contexto embutido.

**Recomendado (opcional)**

Avise quando o passageiro concluir a ação, para liberarmos o desconto na hora
em vez de esperar o tempo mínimo:

```js
window.parent.postMessage({ type: 'sponsor:completed' }, 'https://tocantinstransportewifi.com.br');
```

**Importante sobre a conexão:** o passageiro está num captive portal e ainda
**não tem internet liberada**. Precisamos liberar seu domínio no firewall dos
roteadores. Mande a lista completa de domínios que sua página carrega —
incluindo CDNs de fonte, script e imagem. Se faltar algum, a página abre quebrada.

---

## Nível 3 — API (handoff)

Para quem precisa de dados do passageiro (ex.: CPF para consulta de crédito) e
quer levá-lo para um ambiente próprio.

Nosso servidor chama o seu, recebe um link personalizado e leva o passageiro até lá.
**Todas as chamadas são servidor-a-servidor.** O navegador do passageiro nunca
toca na sua API.

### O que você precisa expor

#### 1. Token — `POST {base_url}/token`

OAuth2 `client_credentials`, `application/x-www-form-urlencoded`:

```
grant_type=client_credentials&client_id=...&client_secret=...
```

Resposta `200`:

```json
{ "access_token": "...", "token_type": "Bearer", "expires_in": 7200 }
```

Reaproveitamos o token até perto de vencer. Não pedimos um por passageiro.

#### 2. Sessão — `POST {base_url}/sessions`

```
Authorization: Bearer {access_token}
Content-Type: application/json
```

```json
{
  "external_id": "uuid-gerado-por-nos",
  "document": "52998224725",
  "consent": {
    "accepted_at": "2026-10-24T18:00:00.000Z",
    "ip_address": "179.86.85.18",
    "user_agent": "Mozilla/5.0 (Linux; Android 14) ..."
  }
}
```

Resposta `201`:

```json
{
  "session_id": "8f0c2a4e-6b1d-4f7a-9a51-2f0b7c3d9e10",
  "url": "https://seuapp.com.br/p#token",
  "expires_at": "2026-10-24T18:05:00.000Z"
}
```

- `document` só é enviado se você precisar de CPF — e só depois do passageiro
  autorizar explicitamente no nosso portal.
- `url` é para onde mandamos o passageiro. **Ela deve já identificar a sessão**,
  para ele não ter que digitar o CPF de novo. Se a url abrir num formulário em
  branco, a integração perde o sentido.

#### 3. Webhook (recomendado)

Sem isso, só conseguimos inferir a exposição por tempo decorrido — impreciso.

```
POST {nosso_webhook}
{ "session_id": "...", "status": "completed", "occurred_at": "..." }
```

Assine com HMAC-SHA256 no header `X-Signature`, usando um segredo combinado.

### Erros

Use os códigos padrão. Respeitamos:

| HTTP | Significado | O que fazemos |
|---|---|---|
| 400 | payload inválido | não repetimos |
| 401 | token vencido | renovamos e repetimos 1x |
| 422 | não é possível atender este documento | seguimos sem desconto |
| 429 | limite excedido | respeitamos o `Retry-After` |
| 502/503 | indisponível | repetimos 1x, depois desistimos |

### Se a sua API não couber nessa spec

Sem problema — escrevemos um adaptador do nosso lado. Mande a documentação
que a gente avalia. Caminhos, nomes de campo e formato de payload diferentes
se resolvem por configuração.

---

## Exigências comuns a todos os níveis

**Conteúdo**

- Nada de conteúdo adulto, jogos de azar, política ou religião.
- Se houver oferta de crédito ou produto financeiro, o texto obrigatório do
  regulador é responsabilidade sua.
- O passageiro pode recusar a qualquer momento e seguir para o pagamento.

**Dados**

- Só coletamos CPF quando o parceiro exige e o passageiro autoriza na tela.
- Guardamos a evidência do consentimento: data/hora, IP, user agent.
- Não repassamos telefone, nome ou e-mail. Só o documento, quando autorizado.
- Credenciais ficam em variável de ambiente no nosso servidor, nunca em código
  ou banco. Token e links de sessão nunca vão para log.

**Operacional**

- Disponibilidade: se sua API/página cair, o passageiro segue para o pagamento
  normal sem travar. Mas sem exposição não há cobrança de veiculação.
- Mudou domínio, CDN ou header? Avise com antecedência — precisamos atualizar
  a liberação nos roteadores.

---

**Contato técnico:** suporte@tocantinstransportewifi.com.br
