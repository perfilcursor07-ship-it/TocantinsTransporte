# ============================================================
# WALLED GARDEN - PARCEIRO CASH CASH
# ============================================================
# Libera SOMENTE os enderecos da Cash Cash para quem ainda nao pagou.
# Com isso a proposta abre dentro da tela de login do WiFi sem liberar a
# internet do passageiro: o celular continua "sem internet", a tela de login
# nao fecha e ele volta ao portal para pagar.
#
# Aplicar em CADA MikroTik (Winbox > New Terminal, ou System > Scripts).
# Pode rodar mais de uma vez: remove as entradas antigas antes de criar.
#
# Conferir depois:
#   /ip hotspot walled-garden print where comment~"Cash Cash"
# ============================================================

:foreach i in=[/ip hotspot walled-garden find comment~"Parceiro: Cash Cash"] do={
    :do { /ip hotspot walled-garden remove $i } on-error={}
}

/ip hotspot walled-garden add dst-host=cashcash.com.br comment="Parceiro: Cash Cash"
/ip hotspot walled-garden add dst-host=*.cashcash.com.br comment="Parceiro: Cash Cash *"
