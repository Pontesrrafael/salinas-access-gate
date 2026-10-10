# Salinas Access Gate - diagnóstico de rede do notebook até o servidor (não usa senha)
param([string]$Servidor = '192.168.4.5')
$saida = Join-Path (Split-Path -Parent $PSScriptRoot) 'diagnostico-rede.txt'
$ErrorActionPreference = 'Continue'
function Sec($t) { "`r`n===== $t =====" }
& {
    Sec 'DATA / COMPUTADOR'; Get-Date -Format 'dd/MM/yyyy HH:mm:ss'; $env:COMPUTERNAME
    Sec 'IPs DESTE COMPUTADOR'
    Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' } | Format-Table InterfaceAlias, IPAddress, PrefixLength -AutoSize | Out-String
    Sec 'ROTA ATÉ O SERVIDOR'
    Find-NetRoute -RemoteIPAddress $Servidor -ErrorAction SilentlyContinue | Select-Object -First 2 InterfaceAlias, IPAddress, NextHop | Format-List | Out-String
    Sec "PING $Servidor"; ping -n 3 $Servidor
    Sec 'ARP (mesma rede física?)'; arp -a | Select-String $Servidor
    foreach ($p in 22, 222, 80, 443) {
        Sec "PORTA $p"
        $t = Test-NetConnection -ComputerName $Servidor -Port $p -WarningAction SilentlyContinue
        "TcpTestSucceeded: $($t.TcpTestSucceeded)   (origem $($t.SourceAddress.IPAddress) via $($t.InterfaceAlias))"
    }
    Sec 'TRACERT (5 saltos)'; tracert -d -h 5 -w 1000 $Servidor
    Sec 'CLIENTES SSH'
    if (Get-Command ssh -ErrorAction SilentlyContinue) { ssh -V 2>&1 } else { 'ssh do Windows: NÃO instalado' }
    foreach ($c in 'C:\Program Files\PuTTY\putty.exe', 'C:\Program Files\PuTTY\pscp.exe') { "$c : $(Test-Path $c)" }
    Sec 'PROXY / VPN'
    netsh winhttp show proxy
    Get-NetAdapter | Where-Object Status -eq 'Up' | Format-Table Name, InterfaceDescription, LinkSpeed -AutoSize | Out-String
} *>&1 | Out-File -FilePath $saida -Encoding utf8
Write-Host "Diagnóstico salvo em: $saida" -ForegroundColor Green
Get-Content $saida | Select-String 'TcpTestSucceeded|Resposta de|Reply from|Esgotado|timed out' | ForEach-Object { $_.Line }
Read-Host 'Pressione Enter para fechar'
