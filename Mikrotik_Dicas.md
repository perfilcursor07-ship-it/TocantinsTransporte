Este arquivo salva todas as configurações, inclusive senhas e licenças.
/system backup save name=config_backup

ARQVUIDO RSC
/export file=config_export

RESTAURAR BACKUP COMPLETO (.backup)
/system backup load name=config_backup.backup
/system backup load name=backup-completo.backup

RESTAURAR EXPORTAÇÃO (.rsc)
/import file-name=config_export.rsc

LOGS LIBERAÇÃO
/log print where topics~"script"

MIKROTIK - 31/08
IP PADRÃO - 192.168.88.1
SENHA PADRAO - KAO4E84OVY

REINICIAR
/system reboot


Outros Mikrotik verificar esse comando
/ip dns static remove [find where address=127.0.0.1]
/ip service set www port=8081
/ip hotspot profile set [find name=hsprof-tocantins] dns-name=login.wifi
/ip dhcp-server network print detail where address=10.5.50.0/24


PARA MIKROTIKS NOVOS, PARA IR PARA CAPTIVE PORTAL
/ip dns static remove [find where address=127.0.0.1]
/ip service set www port=8081