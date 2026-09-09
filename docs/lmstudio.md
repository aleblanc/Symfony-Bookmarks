# LM Studio — configuration

The AI features (auto-tagging, summaries, **AI folder organizer**) call a remote
[LM Studio](https://lmstudio.ai) server over HTTP. This page covers the one
non-obvious setting you **must** raise for the organizer to work: the model's
**context length**.

## Why the context length matters

LM Studio loads each model with a fixed **context length** (window of tokens
shared between the prompt *and* the generated reply). The default is often
**4096**, which is fine for tagging/summaries (small outputs) but **too small
for the folder organizer**:

- Organizing a big folder sends a prompt of ~2500–3000 tokens (one line per
  bookmark + the JSON schema).
- The model then has to generate a large JSON reply (several sub-folders with
  descriptions and examples).
- If *prompt + reply* exceeds the loaded context, LM Studio aborts the request
  with **HTTP 400 "Bad Request"** — after already spending several seconds
  generating. In the UI you see the red banner
  *"Le service IA est indisponible — Bad Request"*.

**Fix: load the model with a context length of at least `8192`** (16384 gives
more headroom). A Qwen3-VL-8B in Q4 easily supports this; the model itself
advertises support up to 262144 tokens.

> Once the context is large enough, the organizer also uses LM Studio's native
> **structured output** (`response_format` / JSON schema), which is *faster* and
> guarantees valid JSON. The code keeps a text-parsing fallback, so a model that
> doesn't support structured output still works — it just needs the room to
> generate the full reply. The model's `output-structured` capability must be
> declared in `config/packages/ai.yaml` (already set for `qwen3-vl-8b-instruct`).

## Where to change it (LM Studio 0.3.x)

The context length is a **load-time** setting, so it is edited either in the
model's *default parameters* or when (re)loading the model.

### Option A — set the model default (recommended, persists)

1. Left sidebar → **My Models** (folder icon 📁).
2. Find your model (e.g. `qwen/qwen3-vl-8b`) → click the **gear ⚙️** on its row
   → **Edit model default parameters**.
3. In **Change source model file**, confirm the right variant is selected
   (e.g. *Qwen3 VL 8B Instruct · Q4_K_M* — the one your app uses).
4. Open the **Load** tab → **Longueur de contexte / Context Length** → set
   **8192**.
5. **Close**. These become the defaults every time the model loads (chat *and*
   server).

> Note: the *default parameters* header may show the model **family**
> (`qwen3-vl-8b`) while your app config uses the file name
> (`qwen3-vl-8b-instruct`). These are the same model — LM Studio groups
> variants under the family name. The "Change source model file" line confirms
> which variant the defaults apply to.

### Option B — set it at load time

1. Top bar → **Choisissez un modèle à charger** (Ctrl+L) → pick the model.
2. In the load configuration panel, set **Context Length = 8192** before
   loading.

### Apply to the running server

The **Developer** tab shows the currently-served model. Default/load changes
only take effect on a fresh load, so after changing the value:

1. **Developer** tab → **⏏ Eject** the model.
2. Reload the **same variant** (Qwen3 VL 8B **Instruct** Q4_K_M).
3. Make sure **Status: Running** (green) and **Start Server** is on.

Confirm reachability from the app via the sidebar **AI server** row → 🔌 button
(it pings `/v1/models`).

## Related timeouts (large folders)

Generating over a big folder can take 30–120s. The web request must be allowed
to wait that long end-to-end:

- **PHP**: handled in code — the organize actions call `set_time_limit(180)`.
- **nginx** (production): raise `fastcgi_read_timeout` (default 60s) in the
  PHP location, e.g. `fastcgi_read_timeout 180;`, then reload nginx. Otherwise
  you get a **504 Gateway Timeout**.
- **php-fpm**: if `request_terminate_timeout` is set in the pool, raise it to
  match (≥180).

## Model catalog note

Each model name referenced by an agent must be registered under
`ai.model.lmstudio` in `config/packages/ai.yaml`, using the **exact** id LM
Studio serves it as (see the API identifier in the Developer tab, e.g.
`qwen3-vl-8b-instruct`). A quantization suffix like `@q4_k_m` is resolved by LM
Studio and does not need to be in the config.
